<?php

use Infinex\Exceptions\Error;
use Infinex\Validation\validateId;
use Decimal\Decimal;

class VotingsAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $votings;
    private $projects;
    private $powerAssetid;
    private $multiplier;
    
    function __construct($log, $amqp, $pdo, $votings, $projects, $powerAssetid, $multiplier) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> votings = $votings;
        $this -> projects = $projects;
        $this -> powerAssetid = $powerAssetid;
        $this -> multiplier = $multiplier;
        
        $this -> log -> debug('Initialized votings API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/votings', [$this, 'getAllVotings']);
        $rc -> get('/votings/{votingid}', [$this, 'getVoting']);
        $rc -> patch('/votings/current', [$this, 'vote']);
    }
    
    public function getAllVotings($path, $query, $body, $auth) {
        $resp = $this -> votings -> getVotings([
            'archive' => isset($query['archive']),
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        foreach($resp['votings'] as $k => $v)
            $resp['votings'][$k] = $this -> ptpVoting($v);
        
        return $resp;
    }
    
    public function getVoting($path, $query, $body, $auth) {
        if($path['votingid'] == 'current')
            $voting = $this -> votings -> getVoting([
                'current' => true
            ]);
        else
            $voting = $this -> votings -> getVoting([
                'votingid' => $path['votingid']
            ]);
        
        return $this -> ptpVoting($voting);
    }
    
    public function vote($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['projectid']))
            throw new Error('MISSING_DATA', 'projectid', 400);
        if(!isset($body['votes']))
            throw new Error('MISSING_DATA', 'votes', 400);
        
        if(!validateId($body['projectid']))
            throw new Error('VALIDATION_ERROR', 'projectid', 400);
        if(!is_int($body['votes']) || $body['votes'] < 1)
            throw new Error('VALIDATION_ERROR', 'votes', 400);
        
        $voting = $this -> votings -> getVoting([
            'current' => true
        ]);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getBalance',
            [
                'uid' => $auth['uid'],
                'assetid' => $this -> powerAssetid
            ]
        ) -> then(function($balance) use($th, $auth, $body, $voting) {
            $avblVotes = new Decimal($balance['total']);
            $avblVotes *= $th -> multiplier;
            $avblVotes = $avblVotes -> floor();
            
            $th -> pdo -> beginTransaction();
            
            $task = [
                ':uid' => $auth['uid']
            ];
            
            $sql = 'SELECT votes
                    FROM user_utilized_votes
                    WHERE uid = :uid
                    FOR UPDATE';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $rowUuv = $q -> fetch();
            
            if($rowUuv)
                $avblVotes -= $rowUuv['votes'];
            
            if($avblVotes < 0)
                $avblVotes = 0;
            
            if($body['votes'] > $avblVotes) {
                $th -> pdo -> rollBack();
                throw new Error(
                    'OUT_OF_RANGE',
                    'Cannot give '.$body['votes'].' votes. Available votes: '.$avblVotes,
                    416
                );
            }
            
            $task = [
                ':votingid' => $voting['votingid'],
                ':projectid' => $body['projectid'],
                ':votes' => $body['votes']
            ];
            
            $sql = 'UPDATE projects
                    SET votes = votes + :votes
                    WHERE projectid = :projectid
                    AND votingid = :votingid
                    RETURNING 1';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $row = $q -> fetch();
            
            if(!$row) {
                $th -> pdo -> rollBack();
                throw new Error('NOT_FOUND', 'Project '.$body['projectid'].' not found in current voting', 404);
            }
            
            $task = [
                ':uid' => $auth['uid'],
                ':votes' => $body['votes']
            ];
            
            if($rowUuv)
                $sql = 'UPDATE user_utilized_votes
                        SET votes = votes + :votes
                        WHERE uid = :uid';
            else
                $sql = 'INSERT INTO user_utilized_votes(
                            uid,
                            votes
                        ) VALUES (
                            :uid,
                            :votes
                        )';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            
            $th -> pdo -> commit();
        });
    }
    
    private function ptpProject($record, $winner) {
        return [
            'projectid' => $record['projectid'],
            'symbol' => $record['symbol'],
            'name' => $record['name'],
            'website' => $record['website'],
            'color' => $record['color'],
            'votes' => $record['votes'],
            'winner' => $winner
        ];
    }
    
    private function ptpVoting($record) {
        $respProj = $this -> projects -> getProjects([
            'votingid' => $record['votingid'],
            'orderBy' => 'votes',
            'orderDir' => 'DESC',
            'limit' => 500
        ]);
        
        $projects = [];
        foreach($respProj['projects'] as $k => $v) {
            if($record['current'])
                $winner = null;
            else
                $winner = ($k == 0);
            $projects[] = $this -> ptpProject($v, $winner);
        
        return [
            'votingid' => $record['votingid'],
            'month' => $record['month'],
            'year' => $record['year'],
            'current' => $record['current'],
            'projects' => $projects
        ];
    }
}

?>