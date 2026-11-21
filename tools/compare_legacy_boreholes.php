<?php

// Read-only reconciliation: php tools/compare_legacy_boreholes.php projects.sql gamaneh.sql
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function borehole_compare_source($path, $kind)
{
    $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if (!$lines) {
        throw new RuntimeException("The {$kind} SQL export is unreadable or empty.");
    }

    $project = $kind === 'projects';
    $literal = <<<'REGEX'
'((?:[^'\\]|\\['\\]|'')*)'
REGEX;
    $table = $project ? '(?:emidco_db_)?projects' : '(?:emidco_db_)?gamaneh';
    $columns = $project ? '`?id`?\s*,\s*`?name`?(?:\s*,\s*`?id_2`?)?' : '`?id`?\s*,\s*`?project_id`?\s*,\s*`?name`?';
    $values = $project ? $literal . '(?:\s*,\s*(?:\d+|\x27\d+\x27))?' : '(\d+)\s*,\s*' . $literal;
    $pattern = '/^INSERT\s+INTO\s+`?' . $table . '`?\s*\(\s*' . $columns . '\s*\)\s+VALUES\s*\(\s*(\d+)\s*,\s*' . $values . '\s*\)\s*;?$/iuD';
    $data = [];
    foreach ($lines as $lineNumber => $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
        if ($line === '' || strpos($line, '--') === 0 || $line[0] === '#') {
            continue;
        }
        if (!preg_match($pattern, $line, $parts)) {
            throw new RuntimeException("Unsupported {$kind} export line " . ($lineNumber + 1) . '. Expected one INSERT per line.');
        }
        $id = (int)$parts[1];
        $name = strtr($parts[$project ? 2 : 3], ["\\'" => "'", '\\\\' => '\\', "''" => "'"]);
        if ($id < 1 || isset($data[$id]) || trim($name) === '') {
            throw new RuntimeException("Invalid or duplicate {$kind} source row at line " . ($lineNumber + 1) . '.');
        }
        $data[$id] = $project ? $name : ['legacy_id' => $id, 'project_id' => (int)$parts[2], 'code' => $name];
    }
    if (!$data) {
        throw new RuntimeException("The {$kind} export contained no rows.");
    }
    return $data;
}

function borehole_compare_name($value)
{
    return trim(preg_replace('/[\s\x{200C}]+/u', ' ', strtr($value, ['ي' => 'ی', 'ك' => 'ک'])));
}

function borehole_compare_code($value)
{
    return mb_strtoupper(preg_replace('/[\s\x{200C}\-_]+/u', '', borehole_compare_name($value)), 'UTF-8');
}

function borehole_compare($projects, $sourceHoles, $mines, $registered)
{
    $mineIndex = [];
    foreach ($mines as $mine) {
        foreach ([$mine['mine_name'], $mine['alias_name']] as $name) {
            if ($name !== null && borehole_compare_name($name) !== '') {
                $mineIndex[borehole_compare_name($name)][$mine['id']] = $mine;
            }
        }
    }
    $mapped = [];
    $coveredMines = [];
    $projectResults = [];
    foreach ($projects as $id => $name) {
        $matches = array_values($mineIndex[borehole_compare_name($name)] ?? []);
        if (count($matches) === 1) {
            $mapped[$id] = $matches[0]['id'];
            $coveredMines[$mapped[$id]] = true;
        }
        $projectResults[] = ['project_id' => $id, 'name' => $name, 'mine_matches' => $matches];
    }

    $exactIndex = $variantIndex = $sourceCounts = [];
    foreach ($registered as $record) {
        $exactIndex[$record['mine_id']][$record['borehole_code']][] = $record;
        $variantIndex[$record['mine_id']][borehole_compare_code($record['borehole_code'])][] = $record;
    }
    foreach ($sourceHoles as $hole) {
        if (isset($mapped[$hole['project_id']])) {
            $mineId = $mapped[$hole['project_id']];
            $sourceCounts[$mineId][$hole['code']] = ($sourceCounts[$mineId][$hole['code']] ?? 0) + 1;
        }
    }

    $result = ['present' => [], 'missing' => [], 'ambiguous' => [], 'outside_source' => []];
    $reviewed = [];
    foreach ($sourceHoles as $hole) {
        $mineId = $mapped[$hole['project_id']] ?? null;
        if ($mineId === null) {
            $result['ambiguous'][] = $hole + ['reason' => 'project_not_uniquely_mapped'];
            continue;
        }
        $exact = $exactIndex[$mineId][$hole['code']] ?? [];
        $suggestions = $variantIndex[$mineId][borehole_compare_code($hole['code'])] ?? [];
        foreach (($exact ?: $suggestions) as $record) {
            $reviewed[$record['id']] = true;
        }
        $hole['mine_id'] = $mineId;
        if ($sourceCounts[$mineId][$hole['code']] > 1 || count($exact) > 1) {
            $result['ambiguous'][] = $hole + ['reason' => 'duplicate_pair', 'candidates' => $suggestions];
        } elseif ($exact) {
            $result['present'][] = $hole + ['borehole_id' => $exact[0]['id']];
        } elseif ($suggestions) {
            $result['ambiguous'][] = $hole + ['reason' => 'spelling_variant', 'candidates' => $suggestions];
        } else {
            $result['missing'][] = $hole;
        }
    }
    foreach ($registered as $record) {
        if (!isset($reviewed[$record['id']])) {
            $result['outside_source'][] = $record + ['reason' => isset($coveredMines[$record['mine_id']]) ? 'no_matching_code' : 'mine_not_mapped_from_source'];
        }
    }

    return ['mode' => 'read_only', 'counts' => array_map('count', $result), 'projects' => $projectResults] + $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    if ($argc !== 3) {
        throw new RuntimeException('Usage: php tools/compare_legacy_boreholes.php projects.sql gamaneh.sql');
    }
    $projects = borehole_compare_source($argv[1], 'projects');
    $sourceHoles = borehole_compare_source($argv[2], 'boreholes');
    define('EMCORE_NATIVE_CONTEXT', true);
    require_once __DIR__ . '/../emcore_api/_bootstrap.php';
    $db = emcore_db();
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $mines = $db->query('SELECT id, mine_name, alias_name FROM emcore_mines WHERE deleted_at IS NULL ORDER BY id')->fetchAll();
    $registered = $db->query('SELECT b.id, b.mine_id, b.borehole_code FROM emcore_boreholes b
        JOIN emcore_mines m ON m.id = b.mine_id AND m.deleted_at IS NULL WHERE b.deleted_at IS NULL ORDER BY b.mine_id, b.id')->fetchAll();
    $db->commit();
    echo json_encode(borehole_compare($projects, $sourceHoles, $mines, $registered), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error instanceof PDOException ? 'Could not read the configured EMCORE database.' : $error->getMessage();
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
