<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use React\Promise;

class Votings {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized votings manager');
    }
    
    public function start() {
        $th = $this;
        
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
}

?>