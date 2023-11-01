<?php

use Infinex\Exceptions\Error;
use Decimal\Decimal;

class SubmitAPI {
    private $log;
    private $amqp;
    private $projects;
    private $powerAssetid;
    private $minAmount;
    
    function __construct($log, $amqp, $projects, $powerAssetid, $minAmount) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> projects = $projects;
        $this -> powerAssetid = $powerAssetid;
        $this -> minAmount = $minAmount;
        
        $this -> log -> debug('Initialized submit project API');
    }
    
    public function initRoutes($rc) {
        $rc -> post('/projects', [$this, 'submitProject']);
    }
    
    public function submitProject($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getBalance',
            [
                'assetid' => $this -> powerAssetid,
                'uid' => $auth['uid']
            ]
        ) -> then(function($balance) use($th, $auth, $body) {
            $dTotal = new Decimal($balance['total']);
            if($dTotal < $th -> minAmount)
                throw new Error(
                    'INSUF_BALANCE',
                    'You must hold at least '.$th -> minAmount.' '.$balance['symbol'].' to submit a project',
                    412
                );
            
            $th -> projects -> createProject([
                'uid' => $auth['uid'],
                'symbol' => @$body['symbol'],
                'name' => @$body['name'],
                'website' => @$body['website']
            ]);
        });
    }
}

?>