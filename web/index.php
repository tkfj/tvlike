<?php
// db/tv_system.db へのパス（環境に合わせて調整してください）
$db_out_path = __DIR__ . '/db/tvlike.db';
$db_in_path = __DIR__ . '/db/tvguide.db';

$db_path_ml = __DIR__ . '/db/tvml.db';

# 常にプログラムから候補を返す
$is_cold_start = FALSE;

$currenttime = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
$pasttime = $currenttime->modify('-7 days');
$pastdates = $pasttime->format('Ymd');

$get_pgm_uid = isset($_GET['pgm_uid']) ? $_GET['pgm_uid']:NULL;

try {
    $db_out = new PDO("sqlite:$db_out_path");
    $db_out->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 回答保存用のテーブル（まだ作っていない場合）
    $db_out->exec('CREATE TABLE IF NOT EXISTS interactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pgm_uid INTEGER UNIQUE,
        asof TEXT,
        tuner TEXT,
        bsdate TEXT,
        station_id TEXT,
        station_name TEXT,
        pgm_station_name TEXT,
        pid TEXT,
        event_id TEXT,
        pg_start TEXT,
        pg_end TEXT,
        pg_title TEXT,
        pg_detail TEXT,
        genre TEXT,
        link TEXT,
        interaction TEXT,
        updated_at DATETIME DEFAULT (DATETIME(\'now\', \'localtime\'))
    );');
} catch (PDOException $e) {
    die("DB(out)接続エラー: " . $e->getMessage());
}
try {
    $db_in = new PDO("sqlite:$db_in_path");
    $db_in->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_in->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB(in)接続エラー: " . $e->getMessage());
}
function load_ml_random(?string $interaction) {
    global $db_path_ml;
    global $pastdates;
    try {
        $db_ml = new PDO("sqlite:$db_path_ml");
        $db_ml->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_ml->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("DB(ml)接続エラー: " . $e->getMessage());
    }
    if(is_null($interaction)) {
        $with_ = ", tvml1 AS (
            SELECT * FROM latest_tvml
            WHERE src = 0
            AND pred_label IS NOT NULL
            ORDER BY RANDOM() DESC
            LIMIT 1
        )";
        $params_=[$pastdates];
    }
    else {
        $with_ = ", tvml1 AS (
            SELECT * FROM latest_tvml
            WHERE src = 0
            AND pred_label=?
            AND interaction IS NULL
        )";
        $params_=[$pastdates, $interaction];
    }
    try {
        $stmt = $db_ml->prepare("
            with latest_tvml as (
                select
                *,
                dense_rank() over (order by asof desc) as rk
                from tvml
                where src=0
                and bsdate>=?
            )
            {$with_}
            SELECT *
            FROM tvml1
            ORDER BY RANDOM() DESC
            LIMIT 1
            ;
        ");
        $stmt->execute($params_);
        $pg = $stmt->fetch();
        $stmt->closeCursor();

    } catch (PDOException $e) {
        echo "エラー: " . $e->getMessage();
    }
    return $pg;
}
function load_pgm_random() {
    global $db_in;
    global $pastdates;
    try {
        $stmt = $db_in->query("
            with latest_programs as (
                select
                *,
                dense_rank() over (order by asof desc) as rk
                from programs
            )
            select
            *
            from latest_programs
            where
            rk=1
            and bsdate>='{$pastdates}'
            order by random() desc
            limit 1
            ;
        ");
        $pg = $stmt->fetch();
        unset($pg["rk"]);
        $stmt->closeCursor();

    } catch (PDOException $e) {
        echo "エラー: " . $e->getMessage();
    }
    return $pg;
}

function load_intr_random() {
    global $db_out;
    $stmt = $db_out->query("
        select
        *
        from interactions
        order by random() desc
        limit 1
        ;
    ");
    $pg = $stmt->fetch();
    unset($pg["id"]);
    $stmt->closeCursor();
    return $pg;
}

function load_pgm_id(int $pgm_uid) {
    global $db_in;
    $stmt = $db_in->prepare("
        select
        *
        from programs
        where
        pgm_uid=?
        limit 1
        ;
    ");
    $stmt->execute([$pgm_uid]);
    $pg = $stmt->fetch();
    $stmt->closeCursor();
    return $pg;
}

function set_interaction(string $interaction, int $pgm_uid) {
    global $db_out;
    $in = load_pgm_id($pgm_uid);
    $stmt = $db_out->prepare('
        INSERT INTO interactions (
            pgm_uid,
            asof,
            tuner,
            bsdate,
            station_id,
            station_name,
            pgm_station_name,
            pid,
            event_id,
            pg_start,
            pg_end,
            pg_title,
            pg_detail,
            genre,
            link,
            interaction
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(pgm_uid)
        DO UPDATE SET
            asof = EXCLUDED.asof,
            tuner = EXCLUDED.tuner,
            bsdate = EXCLUDED.bsdate,
            station_id = EXCLUDED.station_id,
            station_name = EXCLUDED.station_name,
            pgm_station_name = EXCLUDED.pgm_station_name,
            pid = EXCLUDED.pid,
            event_id = EXCLUDED.event_id,
            pg_start = EXCLUDED.pg_start,
            pg_end = EXCLUDED.pg_end,
            pg_title = EXCLUDED.pg_title,
            pg_detail = EXCLUDED.pg_detail,
            genre = EXCLUDED.genre,
            link = EXCLUDED.link,
            interaction = EXCLUDED.interaction,
            updated_at = DATETIME(\'now\',\'localtime\')
        ;
    ');
    $stmt->execute([
        $pgm_uid,
        $in['asof'],
        $in['tuner'],
        $in['bsdate'],
        $in['station_id'],
        $in['station_name'],
        $in['pgm_station_name'],
        $in['pid'],
        $in['event_id'],
        $in['pg_start'],
        $in['pg_end'],
        $in['pg_title'],
        $in['pg_detail'],
        $in['genre'],
        $in['link'],
        $interaction,
    ]);
}
$pg = NULL;
if($get_pgm_uid) {
    $pg = load_pgm_id($get_pgm_uid);
}

if($pg){
    //nop
}
elseif($is_cold_start) {
    $pg = load_pgm_random();
}
else {
    $rnd = rand(1,100);
    if ($rnd <= 33) {
        $pg = load_ml_random("n");
    }
    elseif ($rnd <= 50) {
        $pg = load_ml_random("p");
    }
    else {
        $pg = load_ml_random(null);
    }
}
if(! $pg) {
    $pg = load_pgm_random();
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $pgm_uid = $_POST['pgm_uid'];
    $status = $_POST['status']; // 例: "interested", "not_interested", "skip"
    set_interaction($status, $pgm_uid);
    if ($status == 'p') {
        $status_label = '興味あり';
    } 
    elseif ($status == 'n') {
        $status_label = '興味なし';
    } 
    elseif ($status == '_') {
        $status_label = '保留';
    }
    else {
        $status_label = null;
    }
    if ($status_label) {
        $message = "「" . htmlspecialchars($status_label) . "」として処理しました.";
    }
}
$dts = DateTime::createFromFormat('YmdHi', $pg['pg_start']);
$dts_s = $dts->format('Y-m-d H:i');
$dte = DateTime::createFromFormat('YmdHi', $pg['pg_end']);
$dti = $dte->diff($dts);
$dti_m = ($dti->days * 24 * 60) + ($dti->h * 60) + $dti->i;
$station = $pg['pgm_station_name']=='Unknown' ? $pg['station_name'] : str_replace("_", " ", $pg['pgm_station_name']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>番組仕分け</title>
    <style>
        :root { --bg: #f4f7f6; --card: #ffffff; --primary: #3498db; --danger: #e74c3c; --skip: #95a5a6; }
        body { font-family: sans-serif; background: var(--bg); display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        
        .card { background: var(--card); padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 90%; max-width: 500px; text-align: center; transition: transform 0.2s; }
        .card:active { transform: scale(0.98); }
        
        .title { font-size: 1.5rem; font-weight: bold; margin-bottom: 0.5rem; color: #333; }
        .meta { color: #888; font-size: 0.9rem; margin-bottom: 1.5rem; }
        .detail { text-align: left; line-height: 1.6; color: #555; margin-bottom: 2rem; border-left: 4px solid var(--primary); padding-left: 1rem; }
        
        .actions { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        button { border: none; padding: 12px; border-radius: 8px; color: white; cursor: pointer; font-weight: bold; transition: opacity 0.2s; }
        button:hover { opacity: 0.8; }
        .btn-interest { background: var(--primary); }
        .btn-ignore { background: var(--danger); }
        .btn-skip { background: var(--skip); }

        .toast { position: fixed; bottom: 20px; background: #333; color: #fff; padding: 10px 20px; border-radius: 20px; font-size: 0.8rem; opacity: 0; animation: fade 10s forwards; }
        .toast a {
            color: #4db8ff; /* 明るい水色 */
            text-decoration: none;
            font-weight: bold;
            margin-left: 10px;
            padding: 2px 8px;
            border: 1px solid #4db8ff;
            border-radius: 10px;
            transition: background 0.2s, color 0.2s;
        }
        .toast a:hover {
            background: #4db8ff;
            color: #333;
        }
        @keyframes fade { 0% { opacity: 0; } 1% { opacity: 1; } 99% { opacity: 1; } 100% { opacity: 0; } }
        
        .kdb-hint { margin-top: 20px; font-size: 0.8rem; color: #aaa; }
        kbd { background: #eee; border-radius: 3px; padding: 2px 6px; border: 1px solid #ccc; }
    </style>
</head>
<body>

    <?php if ($message): ?>
        <div class="toast" id="toast"><?php echo htmlspecialchars($message); ?> <a href="?pgm_uid=<?php echo $pgm_uid; ?>">やりなおす</a></div>
    <?php endif; ?>

    <div class="card">
        <div class="meta"><?php echo $station; ?> | <?php echo $dts_s; ?> | <?php echo $dti_m; ?>分</div>
        <div class="title"><?php echo $pg['pg_title']; ?></div>
        <div class="detail"><?php echo $pg['pg_detail']; ?></div>

        <form id="sortForm" action="?" method="POST">
            <input type="hidden" name="pgm_uid" value="<?php echo $pg['pgm_uid']; ?>">
            <input type="hidden" name="status" id="statusInput" value="">
            <div class="actions">
                <button type="button" class="btn-interest" onclick="submitForm('p')">興味あり (1)</button>
                <button type="button" class="btn-ignore" onclick="submitForm('n')">興味なし (2)</button>
                <button type="button" class="btn-skip" onclick="submitForm('_')">保留 (3)</button>
            </div>
        </form>
    </div>

    <div class="kdb-hint">
        キーボード操作: <kbd>1</kbd> 興味あり / <kbd>2</kbd> 興味なし / <kbd>3</kbd> 保留
    </div>

    <script>
        function submitForm(status) {
            document.getElementById('statusInput').value = status;
            document.getElementById('sortForm').submit();
        }

        // キーボードショートカットの実装
        window.addEventListener('keydown', (e) => {
            if (e.key === '1') submitForm('p');
            if (e.key === '2') submitForm('n');
            if (e.key === '3') submitForm('_');
        });
    </script>
</body>
</html>