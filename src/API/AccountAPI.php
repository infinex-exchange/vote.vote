<?php

use Infinex\Exceptions\Error;
use Decimal\Decimal;

class AccountAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $powerAssetid;
    private $submitMinAmount;
    private $multiplier;
    
    function __construct($log, $amqp, $pdo, $powerAssetid, $submitMinAmount, $multiplier) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> powerAssetid = $powerAssetid;
        $this -> submitMinAmount = $submitMinAmount;
        $this -> multiplier = $multiplier;
        
        $this -> log -> debug('Initialized account API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/account/votes', [$this, 'getAvblVotes']);
        $rc -> get('/account/submit', [$this, 'getCanSubmit']);
    }
    
    public function getAvblVotes($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getBalance',
            [
                'uid' => $auth['uid'],
                'assetid' => $this -> powerAssetid
            ]
        ) -> then(function($balance) use($th, $auth) {
            $dBalance = new Decimal($balance['total']);
            
            $avblVotes = $dBalance * $th -> multiplier;
            $avblVotes = $avblVotes -> floor();
            
            echo "AVBL: $avblVotes\n\n";
            
            $task = [
                ':uid' => $auth['uid']
            ];
            
            $sql = 'SELECT votes
                    FROM user_utilized_votes
                    WHERE uid = :uid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $rowUuv = $q -> fetch();
            var_dump($rowUuv);
            
            if($rowUuv)
                $avblVotes -= $rowUuv['votes'];
            
            if($avblVotes < 0)
                $avblVotes = 0;
            
            return [
                'votes' => $avblVotes
            ];
        });
    }
    
    public function getCanSubmit($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getBalance',
            [
                'uid' => $auth['uid'],
                'assetid' => $this -> powerAssetid
            ]
        ) -> then(function($balance) use($th) {
            $dBalance = new Decimal($balance['total']);
            
            if($dBalance < $th -> submitMinAmount)
                throw new Error(
                    'INSUF_BALANCE',
                    'You must hold at least '.$th -> minAmount.' '.$balance['symbol'].' to submit a project',
                    406
                );
        });
    }
}

?>