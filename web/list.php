<?php
$db_in_path = __DIR__ . '/db/tvml.db';

try {
    $db_in = new PDO("sqlite:$db_in_path");
    $db_in->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_in->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

$currenttime = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
$currentdates = $currenttime->format('Ymd');

// フィルタリングロジック
$where_clauses = [];
$params = [];

$where_clauses[] = "pred_label IS NOT NULL";
$where_clauses[] = "AND bsdate >= ?";
$params[] = $currentdates;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['filters'])) {
    $filters = $_POST['filters']; // Array of ['logic' => 'AND/OR', 'column' => '...', 'op' => '...', 'value' => '...']

    foreach ($filters as $index => $f) {
        if (empty($f['column']) || empty($f['value'])) continue;

        $logic = ($index === 0) ? '' : $f['logic']; // 最初の条件は論理演算子不要
        $column = preg_replace('/[^a-z0-9_]/', '', $f['column']); // SQLインジェクション対策（簡易）
        $op = $arr_op = $f['op'];
        $val = $f['value'];

        // 演算子のマッピング
        $sql_op = "LIKE";
        if ($op === 'equals') $sql_op = "=";
        if ($op === 'not_equals') $sql_op = "!=";
        if ($op === 'contains') $val = "%" . $val . "%";
        if ($op === 'starts_with') $val = $val . "%";

        $where_clauses[] = "{$logic} {$column} {$sql_op} ?";
        $params[] = $val;
    }
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" ", $where_clauses) : "";

// データ取得
try {
    $query = "WITH
      tvml_rank AS (
      SELECT *, DENSE_RANK() OVER(PARTITION BY bsdate ORDER BY asof DESC) AS rk 
      FROM tvml
      WHERE src=0
    )
    , tvml_latest AS (
      SELECT *
      FROM tvml_rank
      WHERE rk=1
    )
    SELECT * 
    FROM tvml_latest
    $where_sql
    ORDER BY bsdate ASC, pg_start ASC
    LIMIT 2000";
    $stmt = $db_in->prepare($query);
    $stmt->execute($params);
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error_msg = "検索エラー: " . $e->getMessage();
    $programs = [];
}

// フィルタ用カラムの選択肢
$filterable_columns = [
    'pg_title' => '番組名',
    'pgm_station_name' => '放送局',
    'genre' => 'ジャンル',
    'bsdate' => '放送日',
    'pg_start' => '開始時間',
    'interaction' => '興味有無(教師)',
    'pred_label' => '興味有無(AI)'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>番組一覧 - フィルタリング</title>
    <style>
        :root { 
            --bg: #f4f5f7; 
            --card: #ffffff; 
            --primary: #3498db; /* ラベルの青として利用 */
            --danger: #e74c3c;  /* ラベルの赤として利用 */
            --success: #2ecc71; /* ボタン用：緑系に変更 */
            --skip: #95a5a6; 
            --border: #ddd; 
        }
        body { font-family: sans-serif; background: var(--bg); margin: 0; padding: 20px; display: flex; flex-direction: column; align-items: center; }
        
        .container { width: 100%; max-width: 800px; }
        .card { background: var(--card); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; width: 100%; box-sizing: border-box; }
        
        h1 { color: #333; font-size: 1.5rem; margin-bottom: 1rem; text-align: center; }

        /* Filter Builder Styles */
        .filter-row { display: grid; grid-template-columns: 80px 1fr 100px 1fr 40px; gap: 10px; align-items: center; margin: 10px 0; padding: 10px; border-bottom: 1px solid #eee; }
        select, input { padding: 8px; border-radius: 6px; border: 1px solid var(--border); font-size: 0.9rem; }
        
        .btn-add { background: var(--skip); color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-bottom: 10px; }
        .btn-submit { background: var(--success); color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; font-size: 1rem; }
        .btn-remove { background: var(--danger); color: white; border: none; border-radius: 50%; width: 25px; height: 25px; cursor: pointer; line-height: 1; }

        /* ラベル用スタイル */
        .badge-status {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            margin-right: 5px;
            color: white;
        }
        .bg-p { background: var(--primary); }  /* Blue */
        .bg-n { background: var(--danger); }   /* Red */
        .bg-neutral { background: var(--skip); } /* Gray */

        /* List Styles */
        .program-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #eee; }
        .program-item:last-child { border-bottom: none; }
        .prog-info { text-align: left; flex-grow: 1; }
        .prog-title { font-weight: bold; color: #333; display: block; }
        .prog-meta { font-size: 0.8rem; color: #888; margin-top: 4px; display: block; }
        .badge { background: var(--primary); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; }

        .error-box { background: #fdeaea; color: #e74c3c; padding: 10px; border-radius: 8px; margin-bottom: 20px; width: 100%; box-sizing: border-box; }
    </style> <!-- 修正: </string> -> </style> -->
</head>
<body>

<div class="container">
    <h1>番組一覧検索</h1>

    <?php if (isset($error_msg)): ?>
        <div class="error-box"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Filter Form -->
    <form method="POST" id="filterForm">
        <div class="card">
            <div id="filterContainer">
                <!-- Initial Row -->
                <div class="filter-row">
                    <select name="filters[0][logic]">
                        <option value="AND" selected>AND</option>
                        <option value="OR">OR</option>
                    </select>
                    <select name="filters[0][column]">
                        <?php foreach ($filterable_columns as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (isset($_POST['filters'][0]['column']) && $_POST['filters'][0]['column'] == $val) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filters[0][op]">
                        <option value="contains" <?= (isset($_POST['filters'][0]['op']) && $_POST['filters'][0]['op'] == 'contains') ? 'selected' : '' ?>>含む</option>
                        <option value="equals" <?= (isset($_POST['filters'][0]['op']) && $_POST['filters'][0]['op'] == 'equals') ? 'selected' : '' ?>>一致</option>
                        <option value="starts_with" <?= (int)(isset($_POST['filters'][0]['op']) && $_POST['filters'][0]['op'] == 'starts_with') ? 'selected' : '' ?>>前方一致</option>
                    </select>
                    <input type="text" name="filters[0][value]" placeholder="値..." value="<?= htmlspecialchars($_POST['filters'][0]['value'] ?? '') ?>">
                    <span></span> 
                </div>
            </div>

            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="button" class="btn-add" onclick="addFilterRow()">+ 条件を追加</button>
            </div>
            <button type="submit" class="btn-submit">この条件で検索</button>
        </div>
    </form>

    <!-- Results List -->
    <div class="card">
        <?php if (empty($programs)): ?>
            <p style="text-align:center; color:#888;">該当する番組は見つかりませんでした。</p>
        <?php else: ?>
            <div class="list-container">
                <?php foreach ($programs as $prog): ?>
                    <div class="program-item">
                        <div class="prog-info">
                            <span class="prog-title"><?php echo htmlspecialchars($prog['pg_title']); ?></span>
                            <span class="prog-meta">
                                <?php echo htmlspecialchars($prog['pgm_station_name'] ?? ''); ?> | 
                                <?php echo htmlspecialchars(substr($prog['pg_start'], 0, 4)."-".substr($prog['pg_start'], 4, 2)."-".substr($prog['pg_start'], 6, 2)); ?>
                                <?php echo htmlspecialchars(substr($prog['pg_start'], 8, 2).":".substr($prog['pg_start'], 10, 2)); ?> | 
                                <?php echo htmlspecialchars($prog['genre'] ?? ''); ?>
                            </span>
                            
                            <!-- 新規追加要素 -->
                            <div style="margin-top: 6px;">
                                <?php 
                                    // 補助関数：p/n判定用
                                    $get_badge_class = function($val) {
                                        if ($val === 'p') return 'bg-p';
                                        if ($val === 'n') return 'bg-n';
                                        return 'bg-neutral';
                                    };
                                ?>
                                <span class="badge-status <?= $get_badge_class($prog['interaction'] ?? '') ?>">
                                    Int: <?= htmlspecialchars($prog['interaction'] ?? '-') ?>
                                </span>
                                <span class="badge-status <?= $get_badge_class($prog['pred_label'] ?? '') ?>">
                                    Pred: <?= htmlspecialchars($prog['pred_label'] ?? '-') ?>
                                </span>
                                <span class="badge-prob" style="font-size: 0.75rem; color: #666;">
                                    Prob: <?= number_format((float)($prog['pred_proba'] ?? 0), 3) ?>
                                </span>
                            </div>
                        </div>
                        <div class="badge"><?php echo htmlspecialchars($prog['bsdate']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    let rowCount = 1;

    function addFilterRow() {
        const container = document.getElementById('filterContainer');
        const columnsOptions = `<?php foreach ($filterable_columns as $val => $label): ?>
            <option value="<?= $val ?>"><?= $label ?></option>
        <?php endforeach; ?>`;

        const opsOptions = `
            <option value="contains">含む</option>
            <option value="equals">一致</option>
            <option value="starts_with">前方一致</option>
        `;

        const newRow = document.createElement('div');
        newHTML = ''; // 削除
        newRow.className = 'filter-row';
        newRow.innerHTML = `
            <select name="filters[${rowCount}][logic]">
                <option value="AND" selected>AND</option>
                <option value="OR">OR</option>
            </select>
            <select name="filters[${rowCount}][column]">${columnsOptions}</select>
            <select name="filters[${rowCount}][op]">${opsOptions}</select>
            <input type="text" name="filters[${rowCount}][value]" placeholder="値...">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">×</button>
        `;
        container.appendChild(newRow);
        rowCount++;
    }
</script>
</body>
</html>
