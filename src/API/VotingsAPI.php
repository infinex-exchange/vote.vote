<?php

use Infinex\Exceptions\Error;

class VotingsAPI {
    private $log;
    private $votings;
    private $projects;
    
    function __construct($log, $votings, $projects) {
        $this -> log = $log;
        $this -> votings = $votings;
        $this -> projects = $projects;
        
        $this -> log -> debug('Initialized votings API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/votings', [$this, 'getAllVotings']);
        $rc -> get('/votings/{votingid}', [$this, 'getVoting']);
        $rc -> patch('/votings/{votingid}', [$this, 'vote']);
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
        
        $voting = $this -> getVoting($path, [], [], null);
        if(!$voting['current'])
            throw new Error('VOTING_ENDED', 'Voting '.$path['votingid'].' ended', 403);
        
        //
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