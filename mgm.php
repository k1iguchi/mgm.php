<?php
DEFINE("CONN", "mgmserver.local");             # mgmd server
DEFINE("MGM", "/usr/local/mysql/bin/ndb_mgm"); # path for mgm command
DEFINE("NOTIFY_MAIL", "mail address");         # mail address for notification

class mgm {
    private function exec($cmd) {
        $str = MGM . " -c " . CONN . " -e '{$cmd}'";
        $output = "";
        exec($str, $output);
        return $output;
    }

    public function show() {
        $hdPattern = '/^\[(.*)\]/';

        $output = $this->exec("show");
        $result = array();
        $key = "";
        foreach($output as $line) {
            if(empty($line)) {
                continue;
            }
            if(preg_match($hdPattern, $line, $matches)) {
                $key = $matches[1];
                continue;
            }
            if(empty($key)) {
                continue;
            }
            $st = $this->parseShowLine($line);
            $result[$key][$st['id']] = $st['value'];
        }
        return $result;
    }

    public function status($id) {
        $output = $this->exec("{$id} status");
        $pattern = "/^Node *{$id}: (.*)/";
        if(!preg_match($pattern, $output[1], $matches)) {
            throw new Exception("Unknown result: {$output[1]}");
        }
        return $matches[1];
    }
    public function memoryReport($id) {
        $output = $this->exec("{$id} report memory");
        $pattern = '/^Node ([0-9]*): (.*) usage is ([0-9]*)%/';
        $result = array();
        foreach($output as $line) {
            if(!preg_match($pattern, $line, $matches)) {
                continue;
            }
            $result['id'] = $matches[1];
            $result[$matches[2]] = $matches[3];
        }
        return $result;
    }

    private function parseShowLine($line) {
        $pattern = '/^id=([0-9]*)(.*)/';
        if(!preg_match($pattern, $line, $matches)) {
            throw new Exception("Unknown result: {$line}");
        }
        return array(
            'id' => trim($matches[1]),
            'value' => trim($matches[2])
        );
    }

    public function getDataNodes() {
        $showResult = $this->show();
        return $showResult['ndbd(NDB)'];
    }

    public function isStarted($id) {
        $status = $this->status($id);
        return (strpos($status, 'started', 0) === 0);
    }

    public function restart($id) {
        echo "Restarting {$id}.";
        $result = $this->exec("{$id} restart");
    }
    public function start($id) {
        $result = $this->exec("{$id} start");
        var_dump($result);
    }

    public function isNotStarted($id) {
        $status = $this->status($id);
        return (strpos($status, 'not started', 0) === 0);
    }

    private $interupt = false;
    public function waitForStart($id) {
        echo "Waiting for start {$id}:";
        $status = "";
        $count = 0;
        while(!$this->interupt) {
            if($this->isNotStarted($id)) {
                $this->start($id);
            }
            if($this->isStarted($id)) {
                echo "done\n";
                $this->notify("Node {$id} started. count:{$count}");
                return;
            }
            $newStatus = $this->status($id);
            if($status != $newStatus) {
                echo "\n>{$newStatus}\n";
                $status = $newStatus;
            }
            echo ".";
            $count++;
            sleep(3);
        }
    }

    public function rollingRestart() {
        $dataNodes = $this->getDataNodes();

        foreach($dataNodes as $id => $val) {
            $this->restart($id);
            $this->waitForStart($id);
        }
        $this->notify("Rolling restart done.");
    }

    public function notify($msg) {
        if(!NOTIFY_MAIL) {
            return;
        }
        mail(NOTIFY_MAIL, "Notification from mgm.php", $msg);
    }

    public function dataStatus() {
        $dataNodes = $this->getDataNodes();

        foreach($dataNodes as $id => $val) {
            $status = $this->status($id);
            $report = $this->memoryReport($id);
            echo "{$id} {$val} {$status}";
            if($report['Data'] > 85) {
                echo " Data usage is {$report['Data']}% ";
            }
            if($report['Index'] > 85) {
                echo " Index usage is {$report['Index']}% ";
            }
            echo "\n";
        }
    }
}

$cmd = $argv[1];
$mgm = new mgm();
$result = $mgm->$cmd();
if(!empty($result)) {
    echo print_r($result);
}

