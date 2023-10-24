<?php

require __DIR__.'/Projects.php';
require __DIR__.'/Votings.php';

require __DIR__.'/API/SubmitAPI.php';
require __DIR__.'/API/VotingsAPI.php';
require __DIR__.'/API/VotesAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $projects;
    private $votings;
    
    private $submitApi;
    private $votingsApi;
    private $votesApi;
    private $rest;
    
    function __construct() {
        parent::__construct('vote.vote');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> projects = new Projects(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> votings = new Votings(
            $this -> loop,
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> submitApi = new SubmitAPI(
            $this -> log,
            $this -> amqp,
            $this -> projects,
            VOTE_POWER_ASSETID,
            SUBMIT_MIN_AMOUNT
        );
        
        $this -> votingsApi = new VotingsAPI(
            $this -> log,
            $this -> votings,
            $this -> projects
        );
        
        $this -> votesApi = new VotesAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> votings,
            VOTE_POWER_ASSETID,
            BALANCE_MULTIPLIER
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> submitApi,
                $this -> votingsApi,
                $this -> votesApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> projects -> start(),
                    $th -> votings -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $th -> rest -> stop() -> then(
            function() use($th) {
                return Promise\all([
                    $this -> projects -> stop(),
                    $this -> votings -> stop()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>