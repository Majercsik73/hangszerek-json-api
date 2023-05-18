<?php

require "./vendor/autoload.php";

//echo "PHP fut";
// Router - útvonalválasztó
// terminálból telepítjük fel a 3.féltől származó útvonalválasztót, github-ról lemásoljuk a forráskódot, majd módosítjuk azt
// parancssorba: composer require nikic/fast-route
// method, path
$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
    $r->addRoute('GET', '/', 'home');
    $r->addRoute('GET', '/api/instruments', 'getAllInstrumentsHandler');
    $r->addRoute('GET', '/api/instruments/{id}', 'getSingleInstrument');
    $r->addRoute('POST', '/api/instruments', 'createInstrument');
    $r->addRoute('PATCH', '/api/instruments/{id}', 'patchInstrument'); // a patch method összeolvasztja (merge) az
    //adatokat, a PUT method egy az egyben felülírja (ha az adatbázis szerkezetéhez képest eltérés van a kapott
    //adatokban, nem végzi el a módosítást )
    $r->addRoute('DELETE', '/api/instruments/{id}', 'deleteInstrument');
});

function home()
{
    require './build/index.html';
}

function notFoundHandler()
{
    require './build/index.html';
}

function getAllInstrumentsHandler($vars)
{
    //echo "hangszer lista";
    // a header infót először kell megadni, mert később nem lehet módosítani
    header('Content-type: application/json');
    $pdo = getConnection();
    $statement = $pdo->prepare("SELECT * FROM instruments");
    $statement->execute();
    $instruments = $statement->fetchAll(PDO::FETCH_ASSOC);
    //var_dump($instruments);
    echo json_encode($instruments);
}

function getSingleInstrument($vars)
{
    //var_dump($vars);
    //echo "egyedi hangszer";
    header('Content-type: application/json');

    $instrument = getInstrumentById($vars['id']);

    // ha nem létező id-t adnak meg, az alábbi fog lefutni
    if(!$instrument) {
       
        http_response_code(404);
        echo json_encode(getNotFoundByIdError($vars['id']));
        return;
    }
    echo json_encode($instrument);
}

function createInstrument()
{
    header('Content-type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    //var_dump($body);

    $pdo = getConnection();
    $statement = $pdo->prepare(
        "INSERT INTO `instruments` 
        (`name`, `description`, `brand`, `price`, `quantity`) 
        VALUES 
        (?, ?, ?, ?, ?)"
    );
    $statement->execute([
        $body['name'] ?? '', // a ?? -el alapértelmezett értéket adunk
        $body['description'] ?? '',
        $body['brand'] ?? '',
        (int)$body['price'] ?? null,
        (int)$body['quantity'] ?? null,
    ]);

    $id = $pdo->lastInsertId();
    $instrument = getInstrumentById($id);
    echo json_encode($instrument);

}

function deleteInstrument($vars)
{
    header('Content-type: application/json');
    $pdo = getConnection();
    $statement = $pdo->prepare("DELETE FROM instruments WHERE id = ?");
    $statement->execute([$vars['id']]);

    // pdo -s eszköztárból le lehet kérdezni, hogy az adott  lekérdezés mennyi rekordot érintett
    if(!$statement->rowCount()) {
        http_response_code(404);
        echo json_encode(getNotFoundByIdError($vars['id']));
        return;
    }

    echo json_encode(["id" => $vars['id']]);
}

function patchInstrument($vars)
{
    header('Content-type: application/json');
    $instrument = getInstrumentById($vars['id']);
    if(!$instrument) {
        http_response_code(404);
        echo json_encode(getNotFoundByIdError($vars['id']));
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $pdo = getConnection();
    $statement = $pdo->prepare(
        "UPDATE `instruments` SET 
        `name` = ?,
        `description` = ?,
        `brand` = ?,
        `price` = ?,
        `quantity` = ?
        WHERE `id` = ?
        "
    );
    // az adatbázisból beolvasott és a body-ból kapott adatoka merge -öljük
    $statement->execute([
        $body['name'] ?? $instrument['name'],
        $body['description'] ?? $instrument['description'],
        $body['brand'] ?? $instrument['brand'],
        (int)($body['price'] ?? $instrument['price']),
        (int)($body['quantity'] ?? $instrument['quantity']),
        $vars['id']
    ]);

    $instrument = getInstrumentById($vars['id']);
    echo json_encode($instrument);
}

function getNotFoundByIdError($id)
{
    return [
        'error' => [
            'id' => $id,
            'message' => 'invalid instrument id'
        ]
    ];
}

function getInstrumentById($id)
{
    $pdo = getConnection();
    $statement = $pdo->prepare("SELECT * FROM instruments WHERE id = ?");
    $statement->execute([$id]);
    return $statement->fetch(PDO::FETCH_ASSOC);
}

// a PDO létrehozásához a $_SERVER adatai a .htaccess fájlban található
function getConnection()
{
    return new PDO(
        'mysql:host=' . $_SERVER['DB_HOST'] . ';dbname=' . $_SERVER['DB_NAME'],
        $_SERVER['DB_USER'],
        $_SERVER['DB_PASSWORD']
    );
}
// Fetch method and URI from somewhere
// A "method" és az URL kinyerése
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip query string (?foo=bar) and decode URI
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        // ... 404 Not Found
        notFoundHandler();
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        // ... 405 Method Not Allowed
        notFoundHandler();
        break;
    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        // ... call $handler with $vars
        $handler($vars);
        break;
}
