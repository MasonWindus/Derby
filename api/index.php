<?php

declare(strict_types=1);

use Derby\GameEngine;
use Derby\GameRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->setBasePath('/derby/api');

$repo = new GameRepository();

$json = static function (Response $response, array $data, int $status = 200): Response {
    $response->getBody()->write((string) json_encode($data));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
};

$error = static function (Response $response, string $message, int $status = 400) use ($json): Response {
    return $json($response, ['error' => $message], $status);
};

$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

$app->get('/', function(Request $request, Response $response): Response {
    $response->getBody()->write("Hello World");
    return $response;
});


$app->post('/games', function (Request $request, Response $response) use ($repo, $json, $error) {
    $body = (array) $request->getParsedBody();
    $gameName = trim((string) ($body['gameName'] ?? 'New Derby Game'));
    $playerName = trim((string) ($body['playerName'] ?? 'Player 1'));
    $maxPlayers = (int) ($body['maxPlayers'] ?? 4);
    $startingBalanceCents = (int) round(((float) ($body['startingBalance'] ?? 20)) * 100);
    $standardBetCents = (int) round(((float) ($body['standardBet'] ?? 0.25)) * 100);

    if ($gameName === '' || $playerName === '') {
        return $error($response, 'Game name and player name are required');
    }
    if ($maxPlayers < 2 || $maxPlayers > 10) {
        return $error($response, 'Players must be between 2 and 10');
    }
    if ($startingBalanceCents <= 0 || $standardBetCents <= 0) {
        return $error($response, 'Starting balance and standard bet must be greater than 0');
    }

    $gameId = GameEngine::generateGameId(fn(string $id): bool => $repo->idExists($id));
    $playerId = GameEngine::newPlayerId();
    $game = GameEngine::createGame(
        $gameId,
        $gameName,
        $maxPlayers,
        $startingBalanceCents,
        $standardBetCents,
        $playerId,
        $playerName
    );
    $repo->create($game);

    return $json($response, [
        'gameId' => $gameId,
        'playerId' => $playerId,
        'game' => GameEngine::publicView($game, $playerId),
    ], 201);
});

$app->post('/games/{id}/join', function (Request $request, Response $response, array $args) use ($repo, $json, $error) {
    $id = (string) $args['id'];
    $body = (array) $request->getParsedBody();
    $name = trim((string) ($body['playerName'] ?? 'Player'));
    if ($name === '') {
        return $error($response, 'Player name is required');
    }

    try {
        $playerId = GameEngine::newPlayerId();
        $view = $repo->withLock($id, function (array &$game) use ($playerId, $name) {
            GameEngine::addPlayer($game, $playerId, $name);
            return [
                'gameId' => $game['id'],
                'playerId' => $playerId,
                'game' => GameEngine::publicView($game, $playerId),
            ];
        });
        return $json($response, $view, 201);
    } catch (\RuntimeException $e) {
        return $error($response, $e->getMessage(), 400);
    }
});

$app->post('/games/{id}/start', function (Request $request, Response $response, array $args) use ($repo, $json, $error) {
    $id = (string) $args['id'];
    $body = (array) $request->getParsedBody();
    $playerId = (string) ($body['playerId'] ?? '');

    try {
        $view = $repo->withLock($id, function (array &$game) use ($playerId) {
            if ($game['createdBy'] !== $playerId) {
                throw new \RuntimeException('Only the game creator can start the game');
            }
            GameEngine::startGame($game);
            GameEngine::autoplayNpcTurns($game);
            return [
                'game' => GameEngine::publicView($game, $playerId),
            ];
        });

        return $json($response, $view);
    } catch (\RuntimeException $e) {
        return $error($response, $e->getMessage(), 400);
    }
});

$app->post('/games/{id}/roll', function (Request $request, Response $response, array $args) use ($repo, $json, $error) {
    $id = (string) $args['id'];
    $body = (array) $request->getParsedBody();
    $playerId = (string) ($body['playerId'] ?? '');

    if ($playerId === '') {
        return $error($response, 'playerId is required');
    }

    try {
        $view = $repo->withLock($id, function (array &$game) use ($playerId) {
            GameEngine::rollForPlayer($game, $playerId);
            GameEngine::autoplayNpcTurns($game);
            return [
                'game' => GameEngine::publicView($game, $playerId),
            ];
        });
        return $json($response, $view);
    } catch (\RuntimeException $e) {
        return $error($response, $e->getMessage(), 400);
    }
});

$app->get('/games/{id}', function (Request $request, Response $response, array $args) use ($repo, $json, $error) {
    $id = (string) $args['id'];
    $playerId = $request->getQueryParams()['playerId'] ?? null;

    $game = $repo->find($id);
    if ($game === null) {
        return $error($response, 'Game not found', 404);
    }

    if ($game['status'] === 'in_progress') {
        $repo->withLock($id, function (array &$lockedGame) {
            GameEngine::autoplayNpcTurns($lockedGame);
            return null;
        });
        $game = $repo->find($id) ?? $game;
    }

    return $json($response, [
        'game' => GameEngine::publicView($game, is_string($playerId) ? $playerId : null),
    ]);
});
 
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', '*');
});

$app->run();
