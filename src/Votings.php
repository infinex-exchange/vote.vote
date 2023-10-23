<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use React\Promise;

class Votings {
    private $log;
    private $loop;
    private $amqp;
    private $pdo;
    
    private $timerCreateVoting;
    
    function __construct($log, $loop, $amqp, $pdo) {
        $this -> log = $log;
        $this -> loop = $loop;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized votings manager');
    }
    
    public function start() {
        $th = $this;
        
        $this -> timerCreateVoting = $this -> loop -> addPeriodicTimer(
            300,
            function() use($th) {
                $th -> maybeCreateVoting();
            }
        );
        maybeCreateVoting();
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getVotings',
            [$this, 'getVotings']
        );
        
        $promises[] = $this -> amqp -> method(
            'getVoting',
            [$this, 'getVoting']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started votings manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start votings manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> loop -> cancelTimer($this -> timerCreateVoting);
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getVotings');
        $promises[] = $this -> amqp -> unreg('getVoting');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped votings manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop votings manager: '.((string) $e));
            }
        );
    }
    
    public function getVotings($body) {
        if(isset($body['archive']) && !is_bool($body['archive']))
            throw new Error('VALIDATION_ERROR', 'archive');
        
        $pag = new Pagination\Offset(50, 500, $body);
        
        $task = [];
        
        $sql = 'SELECT votingid,
                       month,
                       year
                FROM votings
                WHERE 1=1';
        
        if(@$body['archive']) {
            $task[':month'] = date('n'); // month without 0
            $task[':year'] = date('Y');
            $sql .= ' AND month != :month
                      AND year != :year';
        }
        
        $sql .= ' ORDER BY votingid DESC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $votings = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $votings[] = $this -> rtrVoting($row);
        }
            
        return [
            'votings' => $votings,
            'more' => $pag -> more
        ];
    }
    
    public function getVoting($body) {
        if(isset($body['votingid']) && isset($body['current']))
            throw new Error('ARGUMENTS_CONFLICT', 'Cannot use votingid and current at once');
        else if(isset($body['votingid'])) {
            if(!validateId($body['votingid']))
                throw new Error('VALIDATION_ERROR', 'votingid', 400);
            
            $notFoundMsg = 'Voting '.$body['votingid'].' not found';
        }
        else if(isset($body['current'])) {
            if($body['current'] !== true)
                throw new Error('VALIDATION_ERROR', 'current');
            
            $notFoundMsg = 'No voting is taking place right now';
        }
        else
            throw new Error('MISSING_DATA', 'votingid or current');
        
        $task = [];
        
        $sql = 'SELECT votingid,
                       month,
                       year
                FROM votings
                WHERE 1=1';
        
        if(isset($body['votingid'])) {
            $task[':votingid'] = $body['votingid'];
            $sql .= ' AND votingid = :votingid';
        }
        
        else {
            $task[':month'] = date('n'); // month without 0
            $task[':year'] = date('Y');
            $sql .= ' AND month = :month
                      AND year = :year';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', $notFoundMsg, 404);
            
        return $this -> rtrVoting($row);
    }
    
    private function rtrVoting($row) {
        return [
            'votingid' => $row['votingid'],
            'month' => $row['month'],
            'year' => $row['year'],
            'current' => ($row['month'] == date('n') && $row['year'] == date('Y'))
        ];
    }
    
    private function maybeCreateVoting() {        
        // Check already exists
        $task = [
            ':month' => date('n'),
            ':year' => date('Y')
        ];
        
        $sql = 'SELECT votingid
                FROM votings
                WHERE month = :month
                AND year = :year
                FOR UPDATE';
            
        $this -> pdo -> beginTransaction();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row) {
            $this -> pdo -> rollBack();
            $this -> log -> debug('Have voting '.$row['votingid'].'. No need to create new voting.');
            return;
        }
        
        // Count available projects        
        $sql = "SELECT COUNT(1) AS count
                FROM projects
                WHERE status = 'APPROVED'
                FOR UPDATE";
        
        $q = $this -> pdo -> query($sql);
        $count = $q -> fetch();
        
        if($count['count'] < 2) {
            $this -> pdo -> rollBack();
            $this -> log -> warn('Need to create new voting but dont have more than 2 approved projects.');
            return;
        }
        
        // Create voting
        $sql = 'INSERT INTO votings(
                    month,
                    year
                ) VALUES (
                    :month,
                    :year
                )
                RETURNING votingid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $voting = $q -> fetch();
        
        // Include projects
        $task = [
            ':votingid' => $voting['votingid']
        ];
        
        $sql = "UPDATE projects
                SET votingid = :votingid,
                    votes = 0
                WHERE status = 'APPROVED'";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
        
        $this -> log -> info('Created voting '.$voting['votingid'].' with '.$count['count'].' projects');
    }
}

?>