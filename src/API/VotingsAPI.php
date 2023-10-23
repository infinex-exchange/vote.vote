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
        $rc -> post('/votings', [$this, 'getAllVotings']);
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
        foreach($respProj['projects'] as $k => $v)
            $projects[] = $this -> ptpProject($v, !$record['current'] && $k == 0);
        
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