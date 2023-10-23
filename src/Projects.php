<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use function Infinex\Validation\validateId;
use React\Promise;

class Projects {
    private $log;
    private $amqp;
    private $pdo;
    
    private $allowedStatus = [
        'NEW',
        'APPROVED',
        'INCLUDED'
    ];
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized projects manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getProjects',
            [$this, 'getProjects']
        );
        
        $promises[] = $this -> amqp -> method(
            'getProject',
            [$this, 'getProject']
        );
        
        $promises[] = $this -> amqp -> method(
            'deleteProject',
            [$this, 'deleteProject']
        );
        
        $promises[] = $this -> amqp -> method(
            'editProject',
            [$this, 'editProject']
        );
        
        $promises[] = $this -> amqp -> method(
            'createProject',
            [$this, 'createProject']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started projects manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start projects manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getProjects');
        $promises[] = $this -> amqp -> unreg('getProject');
        $promises[] = $this -> amqp -> unreg('deleteProject');
        $promises[] = $this -> amqp -> unreg('editProject');
        $promises[] = $this -> amqp -> unreg('createProject');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped projects manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop projects manager: '.((string) $e));
            }
        );
    }
    
    public function getProjects($body) {
        if(isset($body['uid']) && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(isset($body['status']) && !in_array($body['status'], $this -> allowedStatus))
            throw new Error('VALIDATION_ERROR', 'status');
        if(isset($body['votingid']) && !validateId($body['votingid']))
            throw new Error('VALIDATION_ERROR', 'votingid');
            
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'symbol',
                'name',
                'website'
            ],
            $body
        );
            
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT projectid,
                       uid,
                       symbol,
                       name,
                       website,
                       status,
                       color,
                       votingid,
                       votes
                FROM projects
                WHERE 1=1';
        
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid = :uid';
        }
        
        if(isset($body['status'])) {
            $task[':status'] = $body['status'];
            $sql .= ' AND status = :status';
        }
        
        if(isset($body['votingid'])) {
            $task[':votingid'] = $body['votingid'];
            $sql .= ' AND votingid = :votingid';
        }
            
        $sql .= $search -> sql()
             .' ORDER BY projectid DESC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $projects = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $projects[] = $this -> rtrProject($row);
        }
            
        return [
            'projects' => $projects,
            'more' => $pag -> more
        ];
    }
    
    public function getPopup($body) {
        if(!isset($body['projectid']))
            throw new Error('MISSING_DATA', 'projectid');
        
        if(!validateId($body['projectid']))
            throw new Error('VALIDATION_ERROR', 'projectid');
        
        $task = [
            ':projectid' => $body['projectid']
        ];
        
        $sql = 'SELECT projectid,
                       uid,
                       symbol,
                       name,
                       website,
                       status,
                       color,
                       votingid,
                       votes
                FROM projects
                WHERE projectid = :projectid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Project '.$body['projectid'].' not found');
            
        return $this -> rtrProject($row);
    }
    
    public function deleteProject($body) {
        if(!isset($body['projectid']))
            throw new Error('MISSING_DATA', 'projectid');
        
        if(!validateId($body['projectid']))
            throw new Error('VALIDATION_ERROR', 'projectid');
        
        $this -> beginTransaction();
        
        $task = [
            ':projectid' => $body['projectid']
        ];
        
        $sql = 'DELETE FROM projects
                WHERE projectid = :projectid
                RETURNING status';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Project '.$body['projectid'].' not found');
        }
        
        if($row['status'] == 'INCLUDED') {
            $this -> pdo -> rollBack();
            throw new Error('FORBIDDEN', 'Included project cannot be removed');
        }
    }
    
    public function editProject($body) {
        if(!isset($body['projectid']))
            throw new Error('MISSING_DATA', 'projectid');
        
        if(!validateId($body['projectid']))
            throw new Error('VALIDATION_ERROR', 'projectid');
        
        if(
            !isset($body['symbol']) &&
            !isset($body['name']) &&
            !isset($body['website']) &&
            !isset($body['status']) &&
            !isset($body['color'])
        )
            throw new Error('MISSING_DATA', 'Nothing to change');
        if(isset($body['symbol']) && !$this -> validateSymbol($body['symbol']))
            throw new Error('VALIDATION_ERROR', 'symbol');
        if(isset($body['name']) && !$this -> validateName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name');
        if(isset($body['website']) && !$this -> validateWebsite($body['website']))
            throw new Error('VALIDATION_ERROR', 'website');
        if(isset($body['status']) && !in_array($body['status'], ['NEW', 'APPROVED']))
            throw new Error('VALIDATION_ERROR', 'status');
        if(isset($body['color']) && !$this -> validateColor($body['color']))
            throw new Error('VALIDATION_ERROR', 'color');
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':projectid' => $body['projectid']
        ];
        
        $sql = 'SELECT status
                FROM projects
                WHERE projectid = :projectid
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $status = $q -> fetch(); 
        
        if(!$status) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Project '.$body['projectid'].' not found');
        }
        
        if(isset($body['status']) && $status['status'] == 'INCLUDED') {
            $this -> pdo -> rollBack();
            throw new Error('FORBIDDEN', 'Cannot change status of included project');
        }
                
        $sql = 'UPDATE projects
                SET projectid = projectid';
        
        if(isset($body['symbol'])) {
            $task[':symbol'] = $body['symbol'];
            $sql .= ', symbol = :symbol';
        }
        
        if(isset($body['name'])) {
            $task[':name'] = $body['name'];
            $sql .= ', name = :name';
        }
        
        if(isset($body['website'])) {
            $task[':website'] = $body['website'];
            $sql .= ', website = :website';
        }
        
        if(isset($body['status'])) {
            $task[':status'] = $body['status'];
            $sql .= ', status = :status';
        }
        
        if(isset($body['color'])) {
            $task[':color'] = $body['color'];
            $sql .= ', color = :color';
        }
        
        $sql .= ' WHERE projectid = :projectid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
    
    public function createProject($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['symbol']))
            throw new Error('MISSING_DATA', 'symbol', 400);
        if(!isset($body['name']))
            throw new Error('MISSING_DATA', 'name', 400);
        if(!isset($body['website']))
            throw new Error('MISSSING_DATA', 'website', 400);
        
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!$this -> validateSymbol($body['symbol']))
            throw new Error('VALIDATION_ERROR', 'symbol', 400);
        if(!$this -> validateName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name', 400);
        if(!$this -> validateWebsite($body['website']))
            throw new Error('VALIDATION_ERROR', 'website', 400);
            
        if(isset($body['status'])) {
            if(!in_array($body['status'], ['NEW', 'APPROVED']))
                throw new Error('VALIDATION_ERROR', 'status');
            
            $status = $body['status'];
        }
        else
            $status = 'NEW';
        
        if(isset($body['color']) && !$this -> validateColor($body['color']))
            throw new Error('VALIDATION_ERROR', 'color');
        
        $task = array(
            ':uid' => $body['uid'],
            ':symbol' => $body['symbol'],
            ':name' => $body['name'],
            ':website' => $body['website'],
            ':status' => $status,
            ':color' => @$body['color']
        );
        
        $sql = 'INSERT INTO projects(
                    uid,
                    symbol,
                    name,
                    website,
                    status,
                    color
                ) VALUES (
                    :uid,
                    :symbol,
                    :name,
                    :website,
                    :status,
                    :color
                )
                RETURNING projectid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return [
            'projectid' => $row['projectid']
        ];
    }
    
    private function rtrProject($row) {
        return [
            'projectid' => $row['projectid'],
            'uid' => $row['uid'],
            'symbol' => $row['symbol'],
            'name' => $row['name'],
            'website' => $row['website'],
            'status' => $row['status'],
            'color' => $row['color'],
            'votingid' => $row['votingid'],
            'votes' => $row['votes']
        ];
    }
    
    private function validateSymbol($symbol) {
        return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
    }
    
    private function validateName($name) {
        return preg_match('/^[a-zA-Z0-9 \-\.]{1,64}$/', $name);
    }
    
    private function validateWebsite($website) {
        if(strlen($website) > 255) return false;
        return preg_match('/^(https?:\/\/)?([a-z0-9\-]+\.)+[a-z]{2,20}(\/[a-z0-9\-\.]+)*\/?$/', $website);
    }
    
    private function validateColor($color) {
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color);
    }
}

?>