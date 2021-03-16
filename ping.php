<?php

interface Result {
    public function getContent(): string;
    public function getLoggedTime(): int;
}

abstract class AbstractResult implements Result {
    protected $logged_at;

    abstract public function getContent(): string;

    public function getLoggedTime(): int {
        return $this->logged_at;
    }
}

class Success extends AbstractResult {
    public function __construct(float $consumed_ms, int $sequence_no, int $logged_at) {
        $this->consumed_ms = $consumed_ms;
        $this->sequence_no = $sequence_no;
        $this->logged_at   = $logged_at;
    }
    public function getContent(): string {
        return "#{$this->sequence_no} Success in {$this->consumed_ms} ms";
    }
}

class Timeout extends AbstractResult {
    public function __construct(int $sequence_no, int $logged_at) {
        $this->sequence_no = $sequence_no;
        $this->logged_at   = $logged_at;
    }
    public function getContent(): string {
        return "#{$this->sequence_no} Timed out.";
    }
}

class Failure extends AbstractResult {
    public function __construct(string $reason, int $logged_at) {
        $this->reason    = $reason;
        $this->logged_at = $logged_at;
    }
    public function getContent(): string {
        return "Failed: {$this->reason}";
    }
}

function parse(string $line): ?Result {
    # PING chatwork.com (143.204.82.104): 56 data bytes
    if (preg_match('/PING.*bytes/', $line) === 1) {
        return null; // header
    }

    $time = time();

    if (preg_match('/^([0-9:.]+).*from ([0-9.]+).*icmp_seq=([0-9]+).*time=([0-9]+\.[0-9]*)/', $line, $m) === 1) {
        # 14:36:04.394181 64 bytes from 172.217.27.78: icmp_seq=53 ttl=119 time=12.082 ms
        # ^^^^^^^^^^^^^^^[1]            ^^^^^^^^^^^^^[2]        ^^[3]           ^^^^^^[4]
        return new Success($m[4], $m[3], $time);
    }

    if (preg_match('/^Request timeout for icmp_seq ([0-9]+)/', $line, $m) === 1) {
        # Request timeout for icmp_seq 912
        return new Timeout($m[1], $time);
    }

    # ping: sendto: No route to host
    return new Failure($line, $time);
}


$command = "/sbin/ping chatwork.com --apple-time";

$handle = popen($command, 'r');

if (!$handle) {
    die("failed to start $command");
} else try {
    while (!feof($handle)) {
        $line = fgets($handle);
        echo ">>> $line";
        var_dump(parse($line));
    }
} finally {
    pclose($handle);
}
