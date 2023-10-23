<?php

require __DIR__.'/Projects.php';
require __DIR__.'/Votings.php';
require __DIR__.'/Votes.php';

require __DIR__.'/API/PopupsAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $popups;
    
    private $popupsApi;
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
        
        $this -> popups = new Popups(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> popupsApi = new PopupsAPI(
            $this -> log,
            $this -> pdo
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> popupsApi
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
                    $th -> popups -> start(),
                    $th -> rest -> start()
                ]);
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        Promise\all([
            $this -> popups -> stop(),
            $this -> rest -> stop()
        ]) -> then(
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