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
    # TODO サマリー表示はnullにする
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

// TODO 対象ホストを指定できるようにする
function ping(string $host = 'google.com') {
    // TODO popenをproc_openに変えてちゃんとプロセス終了する
    $command = "/sbin/ping {$host} --apple-time 2>&1";

    $handle = popen($command, 'r');

    if (!$handle) {
        die("failed to start $command");
    } else try {
        # Server-Sent Events
        header("Content-Type: text/event-stream\n\n");

        while (!feof($handle) && !connection_aborted()) {
            $line = fgets($handle);
            #echo ">>> $line";
            $result = parse($line);
            if (is_null($result)) {
                continue;
            }
            echo "data: {$result->toEvent()}";
            error_log($result->toEvent());
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
<script type="text/javascript">

const X_LENGTH = 100;

config = {
    // The type of chart we want to create
    type: 'line',

    // The data for our dataset
    data: {
        labels: new Array(X_LENGTH),
        datasets: [{
            label: 'ping RTT',
            borderColor: 'rgb(255, 99, 132)',
            data: new Array(X_LENGTH)
        }]
        // TODO ヌルッとした線のつながりをやめる
    },

    options: {
        title: {
            text: 'ping RTT for google.com'
        },
        scales: {
            xAxes: [{
                // type: 'time',
                /* time: {
                    tooltipFormat: 'll HH:mm'
                },
                scaleLabel: {
                    display: true,
                    labelString: 'Date'
                }
                 */
            }],
            yAxes: [{
                ticks: {
                    suggestedMax: 120,  // 120ms
                    stepSize: 10,       //  10ms step
                    suggestedMin: 0,    //   0ms
                },
            }]
        },
    }
};
ctx = document.getElementById('graph').getContext('2d');
chart = new Chart(ctx, config);

var pinger = new EventSource('ping.php?sse=1');

pinger.onmessage = function(e) {
    console.log(e.data);
    var payload = JSON.parse(e.data);
    window.config.data.labels.push(new Date(payload.time * 1000));
    window.config.data.datasets[0].data.push(payload.rtt);

    if (window.config.data.labels.length > X_LENGTH) {
        window.config.data.labels.shift();
        window.config.data.datasets[0].data.shift();
    }

    window.chart.update();
}

pinger.onerror = function(e) {
    // alert("error!");
    console.error(e);
}

</script>
<!-- TODO: start, stop ボタンを作る -->
<!-- TODO: エラーなど表示するエリアを作る -->
</body>
</html>
