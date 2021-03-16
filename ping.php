<?php

interface Result {
    public function toEvent(): string;
}

class Success implements Result {
    public function __construct(float $consumed_ms, int $sequence_no, int $logged_at) {
        $this->consumed_ms = $consumed_ms;
        $this->sequence_no = $sequence_no;
        $this->logged_at   = $logged_at;
    }
    public function toEvent(): string {
        return json_encode([
            'time'      => $this->logged_at,
            'rtt'       => $this->consumed_ms,
            'comment'   => "Success #{$this->sequence_no}",
        ]);
    }
}

class Timeout implements Result {
    public function __construct(int $sequence_no, int $logged_at) {
        $this->sequence_no = $sequence_no;
        $this->logged_at   = $logged_at;
    }
    public function toEvent(): string {
        return json_encode([
            'time'      => $this->logged_at,
            'rtt'       => 0,
            'comment'   => "Timed out #{$this->sequence_no}",
        ]);
    }
}

class Failure implements Result {
    public function __construct(string $reason, int $logged_at) {
        $this->reason    = $reason;
        $this->logged_at = $logged_at;
    }
    public function toEvent(): string {
        return json_encode([
            'time'      => $this->logged_at,
            'rtt'       => 0,
            'comment'   => "Failure: {$this->reason}",
        ]);
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

function ping(string $host = 'chatwork.com') {
    $command = "/sbin/ping {$host} --apple-time 2>&1";

    $handle = popen($command, 'r');

    if (!$handle) {
        die("failed to start $command");
    } else try {
        # Server-Sent Events
        header("Content-Type: text/event-stream\n\n");

        while (!feof($handle)) {
            $line = fgets($handle);
            #echo ">>> $line";
            $result = parse($line);
            if (is_null($result)) {
                continue;
            }
            echo "data: {$result->toEvent()}";
            echo "\n\n";
            flush();
        }
    } finally {
        pclose($handle);
        exit;
    }
}

if (filter_input(INPUT_GET, 'sse') == 1) {
    ping();
}

?>
<html>
<head>
<title>pingraph</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.8.0"></script>
</head>
<body>
<h1>pingraph</h1>
<canvas id="graph"></canvas>
<button id="button">btn</button>
<script type="text/javascript">
config = {
    // The type of chart we want to create
    type: 'line',

    // The data for our dataset
    data: {
    labels: [
            new Date(1234567890),
            new Date(1234567891),
            new Date(1234567892),
            new Date(1234567893),
            new Date(1234567894),
            new Date(1234567895),
            new Date(1234567896),
            new Date(1234567897),
            new Date(1234567898),
            new Date(1234567899),
        ], // 日付が自動で増えていくようにしたい
        datasets: [{
            label: 'ping RTT',
            backgroundColor: 'rgb(255, 99, 132)',
            borderColor: 'rgb(255, 99, 132)',
            //data: [0, 10, 5, 2, 20, 30, 45]
            data: [
                100,
                110,
                120,
                125,
                110,
                130,
                130,
                "",
                90,
            ]
        }]
    },

    // Configuration options go here
    options: {}
};
ctx = document.getElementById('graph').getContext('2d');
chart = new Chart(ctx, config);

i = 100;

document.getElementById('button').onclick = function() {
    window.config.data.labels.push("aaa");
    window.config.data.datasets[0].data.push(i+=10);
    window.chart.update();
};

var pinger = new EventSource('ping.php?sse=1');

pinger.onmessage = function(e) {
    console.log(e.data);
}

pinger.onerror = function(e) {
    alert("error!");
    console.error(e);
}

</script>
</body>
</html>
