<?php
/**
 * t40 – 0.55.2: az AI-hívás nem küld „input: []”-t eszköz-híváshoz.
 *
 * Hiba volt: „messages.1.content.3.tool_use.input: Input should be an object”. A válasz
 * tömbbé olvasva az üres `{}` üres tömbbé vált, és az eszköz-körben visszaküldve `[]` lett.
 * A hamis AI-szerver (pre_http_request) az első körben paraméter nélküli eszközt hív, és
 * rögzíti, mit kap a második körben.
 */

require __DIR__ . '/_keret.php';

if (!defined('SDH_MUHELY_CLAUDE_KEY')) {
    define('SDH_MUHELY_CLAUDE_KEY', 'teszt-kulcs');
}

/* --- A tisztító önmagában ---------------------------------------------------- */

$tisztit = new ReflectionMethod('SDH_Muhely_Asszisztens', 'uzenetek_tisztit');
$tisztit->setAccessible(true);

$be = [
    ['role' => 'user', 'content' => 'Szia'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'text', 'text' => 'Egy pillanat'],
        ['type' => 'tool_use', 'id' => 'a', 'name' => 'mutat', 'input' => []],
        ['type' => 'tool_use', 'id' => 'b', 'name' => 'mutat', 'input' => ['elem' => 'e1']],
    ]],
];
$json = (string) wp_json_encode($tisztit->invoke(null, $be));

T::tartalmaz($json, '"id":"a","name":"mutat","input":{}', 'az üres bemenet objektum ({}) lesz');
T::tartalmaz($json, '"input":{"elem":"e1"}', 'a kitöltött bemenet változatlan');
T::nem_tartalmaz($json, '"input":[]', 'nincs „input”: [] a kérésben');

/* --- Teljes kör hamis AI-szerverrel ------------------------------------------ */

$kerelmek = [];

add_filter('pre_http_request', static function ($elozo, $arg, $cim) use (&$kerelmek) {
    if (strpos((string) $cim, 'anthropic') === false) {
        return $elozo;
    }

    $kerelmek[] = (string) ($arg['body'] ?? '');

    $valasz = count($kerelmek) === 1
        ? ['content' => [['type' => 'text', 'text' => 'Megmutatom.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'mutat', 'input' => new stdClass()]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]
        : ['content' => [['type' => 'text', 'text' => 'Kész.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 12, 'output_tokens' => 3]];

    return ['headers' => [], 'body' => (string) wp_json_encode($valasz), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

$r = t_ajax('sdh_muhely_ai_kerdez', ['szoveg' => 'Mutasd meg a Beállításokat', 'oldal' => wp_json_encode(['kulcs' => 'attekintes'])]);

T::igaz(!empty($r['success']), 'a kérdés válaszol' . (empty($r['success']) ? ' – ' . wp_json_encode($r) : ''));
T::egyezik(2, count($kerelmek), 'két kör ment az AI felé (kérés, eszköz-eredmény)');

if (count($kerelmek) === 2) {
    T::tartalmaz($kerelmek[1], '"name":"mutat","input":{}', 'a második kör az eszköz-hívást objektum-bemenettel küldi vissza');
    T::nem_tartalmaz($kerelmek[1], '"input":[]', 'a második körben nincs „input”: []');
}

T::vege('t40 AI: üres eszköz-bemenet objektumként megy (nincs „Input should be an object”)');
