<?php

declare(strict_types=1);

namespace Derby;

final class GameEngine
{
    private const HORSE_STEPS = [
        2 => 2,
        3 => 5,
        4 => 7,
        5 => 10,
        6 => 13,
        7 => 16,
        8 => 13,
        9 => 10,
        10 => 7,
        11 => 5,
        12 => 2,
    ];

    public static function createGame(
        string $id,
        string $name,
        int $maxPlayers,
        int $startingBalanceCents,
        int $standardBetCents,
        string $creatorPlayerId,
        string $creatorName
    ): array {
        $players = [[
            'id' => $creatorPlayerId,
            'name' => $creatorName,
            'isNpc' => false,
            'eliminated' => false,
            'balanceCents' => $startingBalanceCents,
            'hand' => [],
        ]];

        return [
            'id' => $id,
            'name' => $name,
            'status' => 'waiting',
            'round' => 0,
            'maxPlayers' => $maxPlayers,
            'startingBalanceCents' => $startingBalanceCents,
            'standardBetCents' => $standardBetCents,
            'eliminationMultiplier' => 1,
            'createdBy' => $creatorPlayerId,
            'createdAt' => gmdate(DATE_ATOM),
            'players' => $players,
            'dealerIndex' => 0,
            'turnPlayerId' => null,
            'phase' => 'waiting',
            'potCents' => 0,
            'horses' => self::newHorseState(),
            'scratches' => [],
            'winnerHorse' => null,
            'winnerPlayerId' => null,
            'lastRoll' => null,
            'logs' => [
                self::logLine('Game created by ' . $creatorName),
            ],
        ];
    }

    public static function addPlayer(array &$game, string $playerId, string $name): void
    {
        if ($game['status'] !== 'waiting') {
            throw new \RuntimeException('Game already started');
        }
        if (count($game['players']) >= $game['maxPlayers']) {
            throw new \RuntimeException('Game is full');
        }

        $game['players'][] = [
            'id' => $playerId,
            'name' => $name,
            'isNpc' => false,
            'eliminated' => false,
            'balanceCents' => $game['startingBalanceCents'],
            'hand' => [],
        ];

        self::pushLog($game, $name . ' joined the game');
    }

    public static function startGame(array &$game): void
    {
        if ($game['status'] !== 'waiting') {
            throw new \RuntimeException('Game already started');
        }

        $humanCount = count(array_values(array_filter(
            $game['players'],
            static fn(array $p): bool => $p['isNpc'] === false
        )));

        if ($humanCount < 4) {
            $npcIndex = 1;
            while (count($game['players']) < $game['maxPlayers']) {
                $name = 'NPC ' . $npcIndex;
                $npcIndex++;
                $game['players'][] = [
                    'id' => self::newPlayerId(),
                    'name' => $name,
                    'isNpc' => true,
                    'eliminated' => false,
                    'balanceCents' => $game['startingBalanceCents'],
                    'hand' => [],
                ];
                self::pushLog($game, $name . ' was added');
            }
        }

        $game['status'] = 'in_progress';
        $game['round'] = 0;
        self::setupNextRound($game);
    }

    public static function rollForPlayer(array &$game, string $playerId): void
    {
        if ($game['status'] !== 'in_progress') {
            throw new \RuntimeException('Game is not active');
        }
        if ($game['turnPlayerId'] !== $playerId) {
            throw new \RuntimeException('Not your turn');
        }

        $playerIndex = self::playerIndexById($game, $playerId);
        if ($playerIndex === null || $game['players'][$playerIndex]['eliminated'] === true) {
            throw new \RuntimeException('Player is eliminated');
        }

        self::rollCurrentTurn($game);
    }

    public static function autoplayNpcTurns(array &$game): void
    {
        while ($game['status'] === 'in_progress') {
            $turnId = $game['turnPlayerId'];
            if ($turnId === null) {
                return;
            }

            $idx = self::playerIndexById($game, $turnId);
            if ($idx === null) {
                return;
            }
            if ($game['players'][$idx]['isNpc'] === false) {
                return;
            }

            self::rollCurrentTurn($game);
        }
    }

    public static function publicView(array $game, ?string $viewerId): array
    {
        $viewer = self::playerById($game, $viewerId);
        $players = [];
        foreach ($game['players'] as $player) {
            $isViewer = $viewerId !== null && $player['id'] === $viewerId;
            $players[] = [
                'id' => $player['id'],
                'name' => $player['name'],
                'isNpc' => $player['isNpc'],
                'eliminated' => $player['eliminated'],
                'balanceCents' => $player['balanceCents'],
                'handCount' => count($player['hand']),
                'hand' => $isViewer ? $player['hand'] : [],
            ];
        }

        return [
            'id' => $game['id'],
            'name' => $game['name'],
            'status' => $game['status'],
            'round' => $game['round'],
            'phase' => $game['phase'],
            'potCents' => $game['potCents'],
            'standardBetCents' => $game['standardBetCents'],
            'eliminationMultiplier' => $game['eliminationMultiplier'],
            'turnPlayerId' => $game['turnPlayerId'],
            'turnPlayerName' => self::playerNameById($game, $game['turnPlayerId']),
            'horses' => $game['horses'],
            'scratches' => $game['scratches'],
            'lastRoll' => $game['lastRoll'],
            'winnerHorse' => $game['winnerHorse'],
            'winnerPlayerId' => $game['winnerPlayerId'],
            'viewer' => $viewer !== null ? [
                'id' => $viewer['id'],
                'name' => $viewer['name'],
                'eliminated' => $viewer['eliminated'],
                'balanceCents' => $viewer['balanceCents'],
                'hand' => $viewer['hand'],
                'canRoll' => $game['turnPlayerId'] === $viewer['id'] && $game['status'] === 'in_progress',
            ] : null,
            'players' => $players,
            'logs' => array_slice($game['logs'], -40),
        ];
    }

    public static function generateGameId(callable $exists): string
    {
        $prefixes = ['red', 'blue', 'gold', 'fast', 'lucky', 'wild', 'swift', 'brisk', 'eager', 'prime'];
        $nouns = ['track', 'hoof', 'sprint', 'derby', 'streak', 'stride', 'pacer', 'thunder', 'gallop', 'stable'];

        for ($i = 0; $i < 100; $i++) {
            $id = $prefixes[array_rand($prefixes)] . '-' . $nouns[array_rand($nouns)] . '-' . random_int(100, 999);
            if (!$exists($id)) {
                return $id;
            }
        }

        throw new \RuntimeException('Unable to generate game id');
    }

    public static function newPlayerId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function setupNextRound(array &$game): void
    {
        $game['round']++;
        $game['phase'] = 'scratch';
        $game['potCents'] = 0;
        $game['scratches'] = [];
        $game['horses'] = self::newHorseState();
        $game['winnerHorse'] = null;
        $game['lastRoll'] = null;

        $activeIndexes = self::activePlayerIndexes($game);
        if (count($activeIndexes) <= 1) {
            $game['status'] = 'finished';
            if (count($activeIndexes) === 1) {
                $game['winnerPlayerId'] = $game['players'][$activeIndexes[0]]['id'];
            }
            return;
        }

        if ($game['round'] === 1) {
            $dealerIndex = self::firstActiveIndex($game);
        } else {
            $dealerIndex = self::nextActiveIndex($game, $game['dealerIndex']);
        }
        $game['dealerIndex'] = $dealerIndex;

        foreach ($game['players'] as &$player) {
            $player['hand'] = [];
        }
        unset($player);

        $deck = self::buildDeck();
        shuffle($deck);
        self::dealDeckRoundRobin($game, $deck, $dealerIndex);
        self::assertBalancedHands($game);

        foreach ($activeIndexes as $idx) {
            sort($game['players'][$idx]['hand']);
        }

        $firstRoller = self::nextActiveIndex($game, $dealerIndex);
        $game['turnPlayerId'] = $game['players'][$firstRoller]['id'];

        self::pushLog($game, 'Round ' . $game['round'] . ' started');
    }

    private static function dealDeckRoundRobin(array &$game, array $deck, int $dealerIndex): void
    {
        $current = self::nextActiveIndex($game, $dealerIndex);
        while (count($deck) > 0) {
            $card = array_pop($deck);
            $game['players'][$current]['hand'][] = $card;
            $current = self::nextActiveIndex($game, $current);
        }
    }

    private static function assertBalancedHands(array $game): void
    {
        $counts = [];
        foreach (self::activePlayerIndexes($game) as $idx) {
            $counts[] = count($game['players'][$idx]['hand']);
        }
        if (count($counts) <= 1) {
            return;
        }
        $min = min($counts);
        $max = max($counts);
        if (($max - $min) > 1) {
            throw new \RuntimeException('Card dealing failed balance check');
        }
    }

    private static function rollCurrentTurn(array &$game): void
    {
        $turnId = $game['turnPlayerId'];
        if ($turnId === null) {
            throw new \RuntimeException('No current turn');
        }
        $playerIndex = self::playerIndexById($game, $turnId);
        if ($playerIndex === null) {
            throw new \RuntimeException('Invalid turn player');
        }
        $roller = $game['players'][$playerIndex];

        if ($game['phase'] === 'scratch') {
            self::applyScratchRoll($game, $playerIndex, $roller['name']);
            if (count($game['scratches']) >= 4) {
                $game['phase'] = 'race';
                self::pushLog($game, 'Race started');
            }
            $nextIndex = self::nextActiveIndex($game, $playerIndex);
            $game['turnPlayerId'] = $game['players'][$nextIndex]['id'];
            return;
        }

        if ($game['phase'] === 'race') {
            $sum = self::rollDice();
            $game['lastRoll'] = [
                'playerId' => $roller['id'],
                'playerName' => $roller['name'],
                'value' => $sum,
                'phase' => 'race',
            ];

            $scratchOrder = self::scratchOrderForHorse($game, $sum);
            if ($scratchOrder !== null) {
                $penalty = self::scratchAmountCents($game, $scratchOrder);
                $paid = min($game['players'][$playerIndex]['balanceCents'], $penalty);
                $game['players'][$playerIndex]['balanceCents'] -= $paid;
                $game['potCents'] += $paid;
                self::pushLog($game, $roller['name'] . ' rolled ' . $sum . ' (scratched) and paid ' . self::money($paid));
            } else {
                $game['horses'][$sum]['position']++;
                self::pushLog($game, $roller['name'] . ' rolled ' . $sum . ', horse moved');
                if ($game['horses'][$sum]['position'] >= self::HORSE_STEPS[$sum]) {
                    $game['winnerHorse'] = $sum;
                    self::pushLog($game, 'Horse ' . self::horseLabel($sum) . ' won the race');
                    self::resolvePayoutAndRoundEnd($game);
                    return;
                }
            }

            $nextIndex = self::nextActiveIndex($game, $playerIndex);
            $game['turnPlayerId'] = $game['players'][$nextIndex]['id'];
            return;
        }

        throw new \RuntimeException('Invalid phase');
    }

    private static function applyScratchRoll(array &$game, int $playerIndex, string $playerName): void
    {
        $order = count($game['scratches']) + 1;
        $horse = self::rollUniqueScratchHorse($game);
        $game['scratches'][] = [
            'order' => $order,
            'horse' => $horse,
            'amountCents' => self::scratchAmountCents($game, $order),
        ];
        $game['horses'][$horse]['scratchedOrder'] = $order;
        $game['horses'][$horse]['position'] = -$order;

        $totalCollected = 0;
        foreach (self::activePlayerIndexes($game) as $idx) {
            $count = 0;
            foreach ($game['players'][$idx]['hand'] as $card) {
                if ($card === $horse) {
                    $count++;
                }
            }
            if ($count === 0) {
                continue;
            }

            $owed = $count * self::scratchAmountCents($game, $order);
            $paid = min($owed, $game['players'][$idx]['balanceCents']);
            $game['players'][$idx]['balanceCents'] -= $paid;
            $game['potCents'] += $paid;
            $totalCollected += $paid;

            $game['players'][$idx]['hand'] = array_values(array_filter(
                $game['players'][$idx]['hand'],
                static fn(int $card): bool => $card !== $horse
            ));
        }

        $game['lastRoll'] = [
            'playerId' => $game['players'][$playerIndex]['id'],
            'playerName' => $playerName,
            'value' => $horse,
            'phase' => 'scratch',
        ];

        self::pushLog(
            $game,
            $playerName . ' scratched horse ' . self::horseLabel($horse) . ' for ' . self::money(self::scratchAmountCents($game, $order))
                . ' each card, collected ' . self::money($totalCollected)
        );
    }

    private static function resolvePayoutAndRoundEnd(array &$game): void
    {
        $horse = $game['winnerHorse'];
        if ($horse === null) {
            throw new \RuntimeException('Missing winner horse');
        }

        $activeIndexes = self::activePlayerIndexes($game);
        $shares = [];
        $totalShares = 0;
        foreach ($activeIndexes as $idx) {
            $count = 0;
            foreach ($game['players'][$idx]['hand'] as $card) {
                if ($card === $horse) {
                    $count++;
                }
            }
            $shares[$idx] = $count;
            $totalShares += $count;
        }

        if ($totalShares > 0 && $game['potCents'] > 0) {
            $base = intdiv($game['potCents'], $totalShares);
            $remainder = $game['potCents'] % $totalShares;

            foreach ($shares as $idx => $count) {
                if ($count <= 0) {
                    continue;
                }
                $win = $count * $base;
                $game['players'][$idx]['balanceCents'] += $win;
            }

            if ($remainder > 0) {
                foreach ($shares as $idx => $count) {
                    if ($count <= 0) {
                        continue;
                    }
                    $game['players'][$idx]['balanceCents'] += $remainder;
                    break;
                }
            }
        }

        $game['potCents'] = 0;

        $eliminatedCount = 0;
        foreach ($game['players'] as &$player) {
            if ($player['eliminated'] === false && $player['balanceCents'] <= 0) {
                $player['eliminated'] = true;
                $eliminatedCount++;
                self::pushLog($game, $player['name'] . ' was eliminated');
            }
        }
        unset($player);

        if ($eliminatedCount > 0) {
            $game['eliminationMultiplier'] *= 2 ** $eliminatedCount;
            self::pushLog($game, 'Scratched horse amounts doubled x' . $game['eliminationMultiplier']);
        }

        $remaining = self::activePlayerIndexes($game);
        if (count($remaining) <= 1) {
            $game['status'] = 'finished';
            $game['phase'] = 'finished';
            $game['turnPlayerId'] = null;
            if (count($remaining) === 1) {
                $game['winnerPlayerId'] = $game['players'][$remaining[0]]['id'];
                self::pushLog($game, $game['players'][$remaining[0]]['name'] . ' won the game');
            } else {
                self::pushLog($game, 'All players were eliminated');
            }
            return;
        }

        self::setupNextRound($game);
    }

    private static function activePlayerIndexes(array $game): array
    {
        $indexes = [];
        foreach ($game['players'] as $idx => $player) {
            if ($player['eliminated'] === false) {
                $indexes[] = $idx;
            }
        }
        return $indexes;
    }

    private static function firstActiveIndex(array $game): int
    {
        foreach ($game['players'] as $idx => $player) {
            if ($player['eliminated'] === false) {
                return $idx;
            }
        }
        throw new \RuntimeException('No active players');
    }

    private static function nextActiveIndex(array $game, int $afterIndex): int
    {
        $count = count($game['players']);
        for ($step = 1; $step <= $count; $step++) {
            $idx = ($afterIndex + $step) % $count;
            if ($game['players'][$idx]['eliminated'] === false) {
                return $idx;
            }
        }
        throw new \RuntimeException('No active players');
    }

    private static function newHorseState(): array
    {
        $state = [];
        foreach (self::HORSE_STEPS as $horse => $steps) {
            $state[$horse] = [
                'steps' => $steps,
                'position' => 0,
                'scratchedOrder' => null,
            ];
        }
        return $state;
    }

    private static function buildDeck(): array
    {
        $deck = [];
        for ($horse = 2; $horse <= 12; $horse++) {
            for ($i = 0; $i < 4; $i++) {
                $deck[] = $horse;
            }
        }
        return $deck;
    }

    private static function rollDice(): int
    {
        return random_int(1, 6) + random_int(1, 6);
    }

    private static function rollUniqueScratchHorse(array $game): int
    {
        for ($i = 0; $i < 100; $i++) {
            $sum = self::rollDice();
            if (self::scratchOrderForHorse($game, $sum) === null) {
                return $sum;
            }
        }
        throw new \RuntimeException('Could not roll unique scratch horse');
    }

    private static function scratchOrderForHorse(array $game, int $horse): ?int
    {
        foreach ($game['scratches'] as $scratch) {
            if ($scratch['horse'] === $horse) {
                return (int) $scratch['order'];
            }
        }
        return null;
    }

    private static function scratchAmountCents(array $game, int $order): int
    {
        return $game['standardBetCents'] * $order * $game['eliminationMultiplier'];
    }

    private static function playerIndexById(array $game, ?string $playerId): ?int
    {
        if ($playerId === null) {
            return null;
        }
        foreach ($game['players'] as $idx => $player) {
            if ($player['id'] === $playerId) {
                return $idx;
            }
        }
        return null;
    }

    private static function playerById(array $game, ?string $playerId): ?array
    {
        $idx = self::playerIndexById($game, $playerId);
        return $idx === null ? null : $game['players'][$idx];
    }

    private static function playerNameById(array $game, ?string $playerId): ?string
    {
        $player = self::playerById($game, $playerId);
        return $player['name'] ?? null;
    }

    private static function horseLabel(int $horse): string
    {
        return match ($horse) {
            11 => 'J (11)',
            12 => 'Q (12)',
            default => (string) $horse,
        };
    }

    private static function pushLog(array &$game, string $message): void
    {
        $game['logs'][] = self::logLine($message);
        if (count($game['logs']) > 300) {
            $game['logs'] = array_slice($game['logs'], -300);
        }
    }

    private static function logLine(string $message): string
    {
        return gmdate('H:i:s') . ' UTC | ' . $message;
    }

    private static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
