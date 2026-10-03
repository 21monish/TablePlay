import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/game_access_lease.dart';
import '../theme/tableplay_theme.dart';
import '../widgets/tableplay_brand.dart';
import 'balloon_ascent.dart';
import 'dinosaur_runner.dart';
import 'extended_games.dart';

class GameCenterV2 extends StatefulWidget {
  const GameCenterV2({super.key, required this.api, required this.access});
  final ApiClient api;
  final Map<String, dynamic> access;
  @override
  State<GameCenterV2> createState() => _GameCenterV2State();
}

class ConnectFourV2 extends StatefulWidget {
  const ConnectFourV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<ConnectFourV2> createState() => _ConnectFourV2State();
}

class _ConnectFourV2State extends State<ConnectFourV2> {
  List<int> board = List.filled(42, 0);
  int turn = 1;
  int winner = 0;
  int moves = 0;

  Future<void> _drop(int column) async {
    if (winner != 0) return;
    var target = -1;
    for (var row = 5; row >= 0; row--) {
      final index = row * 7 + column;
      if (board[index] == 0) {
        target = index;
        break;
      }
    }
    if (target < 0) return;
    final player = turn;
    setState(() {
      board[target] = player;
      moves++;
      if (_hasFour(target, player)) winner = player;
      if (winner == 0 && moves < 42) turn = player == 1 ? 2 : 1;
    });
    if (winner != 0) {
      try {
        await widget.api.post('/games/results', {
          'game_id': widget.game['id'],
          'player_name': 'Player $winner',
          'player_position': winner,
          'score': max(100, 1200 - moves * 10),
        });
      } catch (_) {}
    }
  }

  bool _hasFour(int index, int player) {
    final row = index ~/ 7, column = index % 7;
    for (final direction in const [
      [0, 1],
      [1, 0],
      [1, 1],
      [1, -1]
    ]) {
      var count = 1;
      for (final sign in const [-1, 1]) {
        var r = row + direction[0] * sign;
        var c = column + direction[1] * sign;
        while (
            r >= 0 && r < 6 && c >= 0 && c < 7 && board[r * 7 + c] == player) {
          count++;
          r += direction[0] * sign;
          c += direction[1] * sign;
        }
      }
      if (count >= 4) return true;
    }
    return false;
  }

  void _reset() => setState(() {
        board = List.filled(42, 0);
        turn = 1;
        winner = 0;
        moves = 0;
      });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('${widget.game['name']}')),
        body: Center(
            child: SingleChildScrollView(
                padding: const EdgeInsets.all(20),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: Column(children: [
                    Text(
                        winner > 0
                            ? 'Player $winner wins!'
                            : (moves == 42
                                ? 'Draw game'
                                : 'Player $turn · choose a column'),
                        key: const Key('connect-four-status'),
                        style: const TextStyle(
                            fontSize: 22, fontWeight: FontWeight.w900)),
                    const SizedBox(height: 18),
                    Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                            color: TablePlayColors.deep,
                            borderRadius: BorderRadius.circular(22)),
                        child: GridView.builder(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          itemCount: 42,
                          gridDelegate:
                              const SliverGridDelegateWithFixedCrossAxisCount(
                                  crossAxisCount: 7,
                                  mainAxisSpacing: 6,
                                  crossAxisSpacing: 6),
                          itemBuilder: (_, index) => InkWell(
                              key: Key('connect-cell-$index'),
                              onTap: () => _drop(index % 7),
                              borderRadius: BorderRadius.circular(50),
                              child: Container(
                                  decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      color: board[index] == 1
                                          ? TablePlayColors.accent
                                          : (board[index] == 2
                                              ? TablePlayColors.gold
                                              : Colors.white24)))),
                        )),
                    const SizedBox(height: 18),
                    Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: const [
                          Icon(Icons.circle, color: TablePlayColors.accent),
                          SizedBox(width: 6),
                          Text('Player 1'),
                          SizedBox(width: 24),
                          Icon(Icons.circle, color: TablePlayColors.gold),
                          SizedBox(width: 6),
                          Text('Player 2'),
                        ]),
                    const SizedBox(height: 16),
                    OutlinedButton.icon(
                        onPressed: _reset,
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('New game')),
                  ]),
                ))),
      );
}

class TableRaceV2 extends StatefulWidget {
  const TableRaceV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<TableRaceV2> createState() => _TableRaceV2State();
}

class _TableRaceV2State extends State<TableRaceV2> {
  static const colors = [
    TablePlayColors.accent,
    TablePlayColors.deep,
    TablePlayColors.gold,
    Color(0xff6d5bd0)
  ];
  final random = Random();
  List<int> positions = [0, 0, 0, 0];
  int turn = 0;
  int lastRoll = 0;
  int winner = -1;
  int rolls = 0;

  Future<void> _roll() async {
    if (winner >= 0) return;
    final value = random.nextInt(6) + 1;
    final player = turn;
    setState(() {
      lastRoll = value;
      rolls++;
      positions[player] = min(30, positions[player] + value);
      if (positions[player] >= 30) {
        winner = player;
      } else {
        turn = (turn + 1) % 4;
      }
    });
    if (winner >= 0) {
      try {
        await widget.api.post('/games/results', {
          'game_id': widget.game['id'],
          'player_name': 'Player ${winner + 1}',
          'player_position': winner + 1,
          'score': max(100, 2000 - rolls * 15),
        });
      } catch (_) {}
    }
  }

  void _reset() => setState(() {
        positions = [0, 0, 0, 0];
        turn = 0;
        lastRoll = 0;
        winner = -1;
        rolls = 0;
      });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('${widget.game['name']}')),
        body: Center(
            child: SingleChildScrollView(
                padding: const EdgeInsets.all(20),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: Column(children: [
                    Text(
                        winner >= 0
                            ? 'Player ${winner + 1} wins the table race!'
                            : 'Player ${turn + 1} to roll',
                        key: const Key('table-race-status'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            fontSize: 22, fontWeight: FontWeight.w900)),
                    const SizedBox(height: 8),
                    Text(
                        lastRoll == 0
                            ? 'First player to reach 30 wins.'
                            : 'Last roll: $lastRoll',
                        style: const TextStyle(color: TablePlayColors.muted)),
                    const SizedBox(height: 20),
                    ...List.generate(
                        4,
                        (player) => Container(
                            margin: const EdgeInsets.only(bottom: 10),
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(16),
                                border: Border.all(
                                    color: turn == player && winner < 0
                                        ? colors[player]
                                        : TablePlayColors.border,
                                    width: 2)),
                            child: Row(children: [
                              CircleAvatar(
                                  backgroundColor: colors[player],
                                  child: Text('${player + 1}',
                                      style: const TextStyle(
                                          color: Colors.white,
                                          fontWeight: FontWeight.w900))),
                              const SizedBox(width: 12),
                              Expanded(
                                  child: LinearProgressIndicator(
                                      value: positions[player] / 30,
                                      minHeight: 12,
                                      color: colors[player],
                                      borderRadius: BorderRadius.circular(8))),
                              const SizedBox(width: 12),
                              Text('${positions[player]}/30',
                                  key: Key('race-score-$player'),
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w900))
                            ]))),
                    const SizedBox(height: 12),
                    FilledButton.icon(
                        key: const Key('roll-dice'),
                        onPressed: winner < 0 ? _roll : null,
                        icon: const Icon(Icons.casino_rounded),
                        label: Text(lastRoll == 0
                            ? 'Roll dice'
                            : 'Roll for Player ${turn + 1}'),
                        style: FilledButton.styleFrom(
                            minimumSize: const Size(220, 54),
                            backgroundColor: winner < 0
                                ? colors[turn]
                                : TablePlayColors.muted)),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                        onPressed: _reset,
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('New race')),
                  ]),
                ))),
      );
}

class _GameCenterV2State extends State<GameCenterV2> {
  List<dynamic> games = [];
  bool loading = true;
  String playerFilter = 'all';
  late Map<String, dynamic> access;
  Timer? clock;
  Timer? accessPoll;
  bool checkingAccess = false;
  late final GameAccessLease accessLease;

  @override
  void initState() {
    super.initState();
    access = Map<String, dynamic>.from(widget.access);
    accessLease = GameAccessLease()..applyServerState(access);
    widget.api.get('/games').then((value) {
      if (mounted) {
        setState(() {
          games = value;
          loading = false;
        });
      }
    }).catchError((error) {
      if (mounted) {
        setState(() {
          loading = false;
          if (error is ApiException &&
              accessLease.revokeForHttpStatus(error.status)) {
            _syncAccessFromLease();
          }
        });
      }
    });
    clock = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
    _startAccessPolling();
    _refreshAccess();
  }

  void _startAccessPolling() {
    accessPoll?.cancel();
    accessPoll = Timer.periodic(
      const Duration(seconds: 5),
      (_) => _refreshAccess(),
    );
  }

  void _tick() {
    if (!mounted || access['unlocked'] != true) return;
    setState(_syncAccessFromLease);
  }

  Future<void> _refreshAccess() async {
    if (checkingAccess) return;
    checkingAccess = true;
    final requestStartedAt = DateTime.now();
    try {
      final latest = await widget.api.get('/games/access');
      if (mounted) {
        setState(() {
          access = Map<String, dynamic>.from(latest);
          accessLease.applyServerState(
            access,
            requestStartedAt: requestStartedAt,
          );
          _syncAccessFromLease();
        });
      }
    } on ApiException catch (error) {
      if (mounted && accessLease.revokeForHttpStatus(error.status)) {
        setState(_syncAccessFromLease);
      }
    } catch (_) {
      // A transient failure can only preserve the last server-approved lease.
      // The one-second clock continues enforcing its known deadline.
      accessLease.refresh();
    } finally {
      checkingAccess = false;
    }
  }

  void _syncAccessFromLease() {
    access['unlocked'] = accessLease.unlocked;
    access['remaining_seconds'] = accessLease.remainingSeconds;
    if (!accessLease.unlocked) access['reason'] = accessLease.reason;
  }

  @override
  void dispose() {
    clock?.cancel();
    accessPoll?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final unlocked = access['unlocked'] == true;
    final seconds = (access['remaining_seconds'] as num?)?.toInt() ?? 0;
    if (!unlocked) return _LockedGames(reason: '${access['reason'] ?? ''}');
    return ListView(
      padding: const EdgeInsets.all(18),
      children: [
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [TablePlayColors.deep, Color(0xff1c5c50)],
            ),
            borderRadius: BorderRadius.circular(21),
          ),
          child: Row(
            children: [
              Container(
                width: 54,
                height: 54,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: const Icon(
                  Icons.sports_esports_rounded,
                  color: TablePlayColors.gold,
                  size: 29,
                ),
              ),
              const SizedBox(width: 15),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Games unlocked',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 19,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 3),
                    const Text(
                      'Enjoy while your table session is active',
                      style: TextStyle(color: Colors.white60, fontSize: 11),
                    ),
                  ],
                ),
              ),
              _TimerPill(seconds: seconds),
            ],
          ),
        ),
        const SizedBox(height: 22),
        Text(
          'Choose a game',
          style: Theme.of(
            context,
          ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 5),
        const Text(
          'Pick a player mode. Scores are saved to this table session.',
          style: TextStyle(color: TablePlayColors.muted, fontSize: 11),
        ),
        const SizedBox(height: 13),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              _modeChip('all', 'All games', Icons.apps_rounded),
              const SizedBox(width: 7),
              _modeChip('one', '1 player', Icons.person_outline_rounded),
              const SizedBox(width: 7),
              _modeChip('two', '2 players', Icons.people_outline_rounded),
              const SizedBox(width: 7),
              _modeChip('four', '2–4 players', Icons.groups_2_outlined),
            ],
          ),
        ),
        const SizedBox(height: 15),
        if (loading)
          const Center(
            child: Padding(
              padding: EdgeInsets.all(30),
              child: CircularProgressIndicator(),
            ),
          ),
        ...games.where((raw) {
          if (playerFilter == 'all') return true;
          return '${(raw as Map)['player_mode']}' == playerFilter;
        }).map((raw) {
          final game = Map<String, dynamic>.from(raw);
          final playerMode = '${game['player_mode']}';
          final multiplayer = playerMode == 'two' || playerMode == 'four';
          final icon = switch (game['slug']) {
            'snake' => Icons.gesture_rounded,
            'dinosaur-dash' => Icons.directions_run_rounded,
            'balloon-ascent' => Icons.flight_rounded,
            'memory-match' => Icons.extension_rounded,
            'quick-math' => Icons.calculate_rounded,
            'tic-tac-toe' => Icons.grid_3x3_rounded,
            'connect-four' => Icons.blur_circular_rounded,
            'table-race' => Icons.casino_rounded,
            _ => Icons.touch_app_rounded,
          };
          return InkWell(
            onTap: () => open(game),
            borderRadius: BorderRadius.circular(18),
            child: Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: TablePlayColors.border),
              ),
              child: Row(
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      color: (multiplayer
                              ? TablePlayColors.accent
                              : TablePlayColors.deep)
                          .withValues(alpha: .1),
                      borderRadius: BorderRadius.circular(17),
                    ),
                    child: Icon(
                      icon,
                      color: multiplayer
                          ? TablePlayColors.accent
                          : TablePlayColors.deep,
                      size: 30,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          game['name'],
                          style: const TextStyle(
                            fontWeight: FontWeight.w900,
                            fontSize: 15,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          game['description'] ??
                              '${game['player_mode']} player',
                          style: const TextStyle(
                            color: TablePlayColors.muted,
                            fontSize: 11,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          playerMode == 'four'
                              ? '2–4 PLAYERS'
                              : (playerMode == 'two'
                                  ? '2 PLAYERS'
                                  : '1 PLAYER'),
                          style: const TextStyle(
                            color: TablePlayColors.accent,
                            fontSize: 9,
                            fontWeight: FontWeight.w900,
                            letterSpacing: .8,
                          ),
                        ),
                      ],
                    ),
                  ),
                  FilledButton(
                    onPressed: () => open(game),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size(74, 40),
                      padding: const EdgeInsets.symmetric(horizontal: 15),
                      backgroundColor: TablePlayColors.accent,
                    ),
                    child: const Text('Play'),
                  ),
                ],
              ),
            ),
          );
        }),
      ],
    );
  }

  Widget _modeChip(String value, String label, IconData icon) {
    final selected = playerFilter == value;
    final count = value == 'all'
        ? games.length
        : games
            .where((raw) => '${(raw as Map)['player_mode']}' == value)
            .length;
    return ChoiceChip(
      selected: selected,
      onSelected: (_) => setState(() => playerFilter = value),
      avatar: Icon(
        icon,
        size: 16,
        color: selected ? TablePlayColors.accent : TablePlayColors.muted,
      ),
      label: Text('$label · $count'),
    );
  }

  void open(Map<String, dynamic> game) {
    if (!accessLease.unlocked) return;
    accessPoll?.cancel();
    final screen = switch (game['slug']) {
      'tic-tac-toe' => TicTacToeV2(game: game, api: widget.api),
      'snake' => SnakeGameV2(game: game, api: widget.api),
      'dinosaur-dash' => DinosaurRunnerV2(game: game, api: widget.api),
      'balloon-ascent' => BalloonAscentV2(game: game, api: widget.api),
      'memory-match' => MemoryMatchV2(game: game, api: widget.api),
      'quick-math' => QuickMathV2(game: game, api: widget.api),
      'connect-four' => ConnectFourV2(game: game, api: widget.api),
      'table-race' => TableRaceV2(game: game, api: widget.api),
      'reaction-duel' => ReactionDuelV2(game: game, api: widget.api),
      'air-hockey' => AirHockeyV2(game: game, api: widget.api),
      'quiz-battle' => QuizBattleV2(game: game, api: widget.api),
      'tap-elimination' => TapEliminationV2(game: game, api: widget.api),
      'ludo-mini' => LudoMiniV2(game: game, api: widget.api),
      _ => TapChallengeV2(game: game, api: widget.api),
    };
    Navigator.of(context)
        .push(
      MaterialPageRoute(
        builder: (_) => _GameAccessGuard(
          api: widget.api,
          initialAccess: Map<String, dynamic>.from(access),
          child: screen,
        ),
      ),
    )
        .then((_) {
      if (!mounted) return;
      _startAccessPolling();
      _refreshAccess();
    });
  }
}

class _GameAccessGuard extends StatefulWidget {
  const _GameAccessGuard({
    required this.api,
    required this.initialAccess,
    required this.child,
  });

  final ApiClient api;
  final Map<String, dynamic> initialAccess;
  final Widget child;

  @override
  State<_GameAccessGuard> createState() => _GameAccessGuardState();
}

class _GameAccessGuardState extends State<_GameAccessGuard> {
  Timer? poll;
  Timer? localClock;
  late final GameAccessLease accessLease;
  bool allowed = false;
  String lockedReason = 'timer';
  bool checking = false;
  bool terminated = false;

  @override
  void initState() {
    super.initState();
    accessLease = GameAccessLease()..applyServerState(widget.initialAccess);
    allowed = accessLease.unlocked;
    lockedReason = accessLease.reason;
    if (!allowed) terminated = true;
    poll = Timer.periodic(const Duration(seconds: 5), (_) => check());
    localClock = Timer.periodic(
      const Duration(milliseconds: 500),
      (_) => enforceLocalExpiry(),
    );
    check();
  }

  Future<void> check() async {
    if (checking || terminated) return;
    checking = true;
    final requestStartedAt = DateTime.now();
    try {
      final state = await widget.api.get('/games/access');
      if (!mounted || terminated) return;
      accessLease.applyServerState(
        Map<String, dynamic>.from(state),
        requestStartedAt: requestStartedAt,
      );
      _applyLeaseState(terminalWhenLocked: true);
    } on ApiException catch (error) {
      if (mounted && accessLease.revokeForHttpStatus(error.status)) {
        _applyLeaseState(terminalWhenLocked: true);
      }
    } catch (_) {
      // Keep playing only while the last successful server lease is valid.
      if (mounted) _applyLeaseState(terminalWhenLocked: true);
    } finally {
      checking = false;
    }
  }

  void enforceLocalExpiry() {
    if (!mounted || terminated) return;
    accessLease.refresh();
    _applyLeaseState(terminalWhenLocked: true);
  }

  void _applyLeaseState({required bool terminalWhenLocked}) {
    final nextAllowed = accessLease.unlocked;
    final nextReason = accessLease.reason;
    if (!nextAllowed && terminalWhenLocked) {
      terminated = true;
      poll?.cancel();
      localClock?.cancel();
    }
    if (nextAllowed == allowed && nextReason == lockedReason) return;
    setState(() {
      allowed = nextAllowed;
      lockedReason = nextReason;
    });
  }

  @override
  void dispose() {
    poll?.cancel();
    localClock?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => allowed
      ? widget.child
      : Scaffold(
          appBar: AppBar(title: const Text('Game session ended')),
          body: _LockedGames(reason: lockedReason),
        );
}

class _TimerPill extends StatelessWidget {
  const _TimerPill({required this.seconds});
  final int seconds;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(13),
        ),
        child: Column(
          children: [
            Text(
              '${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}',
              style: const TextStyle(
                color: Colors.white,
                fontSize: 17,
                fontWeight: FontWeight.w900,
              ),
            ),
            const Text(
              'LEFT',
              style: TextStyle(
                color: Colors.white54,
                fontSize: 8,
                fontWeight: FontWeight.w800,
                letterSpacing: 1,
              ),
            ),
          ],
        ),
      );
}

class _LockedGames extends StatelessWidget {
  const _LockedGames({this.reason});

  final String? reason;

  @override
  Widget build(BuildContext context) {
    final unavailableInPlan = reason == 'plan';
    final accessEnded = reason == 'access' || reason == 'session';

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Container(
            padding: const EdgeInsets.all(32),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(23),
              border: Border.all(color: TablePlayColors.border),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 76,
                  height: 76,
                  decoration: BoxDecoration(
                    color: TablePlayColors.deep.withValues(alpha: .08),
                    borderRadius: BorderRadius.circular(23),
                  ),
                  child: const Icon(
                    Icons.lock_rounded,
                    size: 38,
                    color: TablePlayColors.deep,
                  ),
                ),
                const SizedBox(height: 20),
                Text(
                  unavailableInPlan
                      ? 'Games are not included in this plan'
                      : (accessEnded
                          ? 'Game access has ended'
                          : 'Games are locked'),
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        letterSpacing: -.8,
                      ),
                ),
                const SizedBox(height: 8),
                Text(
                  unavailableInPlan
                      ? 'Ask the restaurant administrator to upgrade the TablePlay plan. Ordering and table service remain available.'
                      : (accessEnded
                          ? 'This table session or tablet pairing is no longer active. Ask a staff member if you still need help.'
                          : 'Place an order and wait for the counter to confirm it. Your one-hour game session will unlock automatically.'),
                  textAlign: TextAlign.center,
                  style: TextStyle(color: TablePlayColors.muted, height: 1.5),
                ),
                if (!unavailableInPlan && !accessEnded) ...[
                  const SizedBox(height: 20),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xfffff3da),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.info_outline_rounded,
                          size: 17,
                          color: Color(0xffb86b12),
                        ),
                        SizedBox(width: 7),
                        Flexible(
                          child: Text(
                            'Additional confirmed orders restart the timer.',
                            style: TextStyle(
                              color: Color(0xff8b5913),
                              fontSize: 10.5,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class TapChallengeV2 extends StatefulWidget {
  const TapChallengeV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<TapChallengeV2> createState() => _TapChallengeV2State();
}

class _TapChallengeV2State extends State<TapChallengeV2> {
  int taps = 0, left = 15;
  bool playing = false;
  Timer? timer;
  void start() {
    setState(() {
      taps = 0;
      left = 15;
      playing = true;
    });
    timer = Timer.periodic(const Duration(seconds: 1), (current) {
      if (!mounted) return;
      setState(() => left--);
      if (left <= 0) {
        current.cancel();
        finish();
      }
    });
  }

  Future<void> finish() async {
    setState(() => playing = false);
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Player',
        'score': taps,
        'duration_seconds': 15,
      });
    } catch (_) {}
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.game['name'])),
        body: Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xffeef4f0), TablePlayColors.canvas],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
          child: SafeArea(
            child: Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const TablePlayMark(size: 54),
                  const SizedBox(height: 18),
                  Text(
                    playing
                        ? '$left seconds'
                        : (taps > 0 ? 'Final score' : 'Ready?'),
                    style: const TextStyle(
                      color: TablePlayColors.muted,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '$taps',
                    style: const TextStyle(
                      fontSize: 58,
                      height: 1,
                      fontWeight: FontWeight.w900,
                      color: TablePlayColors.deep,
                    ),
                  ),
                  const Text(
                    'TAPS',
                    style: TextStyle(
                      color: TablePlayColors.accent,
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 2,
                    ),
                  ),
                  const SizedBox(height: 26),
                  if (playing)
                    GestureDetector(
                      onTap: () => setState(() => taps++),
                      child: Container(
                        width: 220,
                        height: 220,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: const LinearGradient(
                            colors: [TablePlayColors.accent, Color(0xffff8b4b)],
                          ),
                          boxShadow: [
                            BoxShadow(
                              color:
                                  TablePlayColors.accent.withValues(alpha: .3),
                              blurRadius: 30,
                              offset: const Offset(0, 14),
                            ),
                          ],
                        ),
                        alignment: Alignment.center,
                        child: const Text(
                          'TAP!',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 42,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                    )
                  else
                    FilledButton.icon(
                      onPressed: start,
                      icon: const Icon(Icons.play_arrow_rounded),
                      label: Text(
                        taps > 0 ? 'Play again' : 'Start 15-second challenge',
                      ),
                      style: FilledButton.styleFrom(
                        backgroundColor: TablePlayColors.accent,
                        minimumSize: const Size(240, 54),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
}

class MemoryMatchV2 extends StatefulWidget {
  const MemoryMatchV2({super.key, required this.game, required this.api});

  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<MemoryMatchV2> createState() => _MemoryMatchV2State();
}

class _MemoryMatchV2State extends State<MemoryMatchV2> {
  static const icons = [
    Icons.local_pizza_rounded,
    Icons.icecream_rounded,
    Icons.local_cafe_rounded,
    Icons.ramen_dining_rounded,
    Icons.cookie_rounded,
    Icons.local_bar_rounded,
  ];

  late List<int> cards;
  final Set<int> matched = {};
  final List<int> revealed = [];
  int moves = 0;
  bool busy = false;
  DateTime startedAt = DateTime.now();

  @override
  void initState() {
    super.initState();
    _newGame();
  }

  void _newGame() {
    cards = [
      for (var i = 0; i < icons.length; i++) ...[i, i],
    ]..shuffle();
    matched.clear();
    revealed.clear();
    moves = 0;
    busy = false;
    startedAt = DateTime.now();
  }

  Future<void> _reveal(int index) async {
    if (busy || matched.contains(index) || revealed.contains(index)) return;
    setState(() => revealed.add(index));
    if (revealed.length < 2) return;

    moves++;
    final first = revealed[0], second = revealed[1];
    if (cards[first] == cards[second]) {
      setState(() {
        matched.addAll([first, second]);
        revealed.clear();
      });
      if (matched.length == cards.length) await _finish();
      return;
    }

    busy = true;
    await Future<void>.delayed(const Duration(milliseconds: 650));
    if (!mounted) return;
    setState(() {
      revealed.clear();
      busy = false;
    });
  }

  Future<void> _finish() async {
    final duration = DateTime.now().difference(startedAt).inSeconds;
    final score = max(100, 1200 - moves * 35 - duration * 3);
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Player',
        'score': score,
        'duration_seconds': duration,
      });
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final complete = matched.length == cards.length;
    return Scaffold(
      appBar: AppBar(title: Text('${widget.game['name']}')),
      body: Container(
        width: double.infinity,
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xffe9f2ed), TablePlayColors.canvas],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 560),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: _GameStat(
                            label: 'MOVES',
                            value: '$moves',
                            icon: Icons.touch_app_rounded,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: _GameStat(
                            label: 'PAIRS',
                            value: '${matched.length ~/ 2}/${icons.length}',
                            icon: Icons.extension_rounded,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 18),
                    GridView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: cards.length,
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 4,
                        mainAxisSpacing: 10,
                        crossAxisSpacing: 10,
                      ),
                      itemBuilder: (_, index) {
                        final visible =
                            revealed.contains(index) || matched.contains(index);
                        return Material(
                          color: visible ? Colors.white : TablePlayColors.deep,
                          borderRadius: BorderRadius.circular(17),
                          child: InkWell(
                            onTap: complete ? null : () => _reveal(index),
                            borderRadius: BorderRadius.circular(17),
                            child: AnimatedSwitcher(
                              duration: const Duration(milliseconds: 180),
                              child: Icon(
                                visible
                                    ? icons[cards[index]]
                                    : Icons.question_mark_rounded,
                                key: ValueKey('$index-$visible'),
                                size: visible ? 32 : 25,
                                color: visible
                                    ? TablePlayColors.accent
                                    : Colors.white54,
                              ),
                            ),
                          ),
                        );
                      },
                    ),
                    const SizedBox(height: 20),
                    if (complete)
                      Container(
                        padding: const EdgeInsets.all(18),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(18),
                          border: Border.all(color: TablePlayColors.border),
                        ),
                        child: Column(
                          children: [
                            const Text(
                              'Perfect match!',
                              style: TextStyle(
                                fontSize: 20,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            const SizedBox(height: 5),
                            Text(
                              'Completed in $moves moves. Score saved.',
                              style: const TextStyle(
                                color: TablePlayColors.muted,
                              ),
                            ),
                            const SizedBox(height: 12),
                            FilledButton.icon(
                              onPressed: () => setState(_newGame),
                              icon: const Icon(Icons.refresh_rounded),
                              label: const Text('Play again'),
                            ),
                          ],
                        ),
                      )
                    else
                      const Text(
                        'Find all six matching food pairs.',
                        style: TextStyle(
                          color: TablePlayColors.muted,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class QuickMathV2 extends StatefulWidget {
  const QuickMathV2({super.key, required this.game, required this.api});

  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<QuickMathV2> createState() => _QuickMathV2State();
}

class _QuickMathV2State extends State<QuickMathV2> {
  final random = Random();
  final answer = TextEditingController();
  final answerFocus = FocusNode();
  Timer? timer;
  int a = 0, b = 0, expected = 0, left = 45, correct = 0, streak = 0;
  String operation = '+';
  bool playing = false;

  void _start() {
    timer?.cancel();
    setState(() {
      left = 45;
      correct = 0;
      streak = 0;
      playing = true;
      _nextQuestion();
    });
    answerFocus.requestFocus();
    timer = Timer.periodic(const Duration(seconds: 1), (current) {
      if (!mounted) return;
      if (left <= 1) {
        current.cancel();
        _finish();
      } else {
        setState(() => left--);
      }
    });
  }

  void _nextQuestion() {
    final subtract = random.nextBool();
    a = random.nextInt(30) + 5;
    b = random.nextInt(20) + 1;
    if (subtract && b > a) {
      final swap = a;
      a = b;
      b = swap;
    }
    operation = subtract ? '−' : '+';
    expected = subtract ? a - b : a + b;
    answer.clear();
  }

  void _submit() {
    if (!playing) return;
    final value = int.tryParse(answer.text.trim());
    if (value == expected) {
      setState(() {
        correct++;
        streak++;
        _nextQuestion();
      });
    } else {
      setState(() {
        streak = 0;
        answer.clear();
      });
    }
    answerFocus.requestFocus();
  }

  Future<void> _finish() async {
    if (!mounted) return;
    setState(() {
      playing = false;
      left = 0;
    });
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Player',
        'score': correct * 100,
        'duration_seconds': 45,
      });
    } catch (_) {}
  }

  @override
  void dispose() {
    timer?.cancel();
    answer.dispose();
    answerFocus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('${widget.game['name']}')),
        body: Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xffe9f2ed), TablePlayColors.canvas],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
          child: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(22),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 480),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: _GameStat(
                              label: 'TIME',
                              value: '${left}s',
                              icon: Icons.timer_rounded,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _GameStat(
                              label: 'SCORE',
                              value: '${correct * 100}',
                              icon: Icons.stars_rounded,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _GameStat(
                              label: 'STREAK',
                              value: '$streak',
                              icon: Icons.local_fire_department_rounded,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 24),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(28),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(24),
                          border: Border.all(color: TablePlayColors.border),
                        ),
                        child: Column(
                          children: [
                            Text(
                              playing
                                  ? '$a $operation $b'
                                  : (correct > 0 ? 'Time up!' : 'Quick Math'),
                              style: const TextStyle(
                                color: TablePlayColors.deep,
                                fontSize: 44,
                                fontWeight: FontWeight.w900,
                                letterSpacing: -1.5,
                              ),
                            ),
                            const SizedBox(height: 20),
                            if (playing) ...[
                              TextField(
                                controller: answer,
                                focusNode: answerFocus,
                                autofocus: true,
                                textAlign: TextAlign.center,
                                keyboardType: TextInputType.number,
                                textInputAction: TextInputAction.done,
                                onSubmitted: (_) => _submit(),
                                style: const TextStyle(
                                  fontSize: 25,
                                  fontWeight: FontWeight.w900,
                                ),
                                decoration: const InputDecoration(
                                  hintText: 'Answer',
                                ),
                              ),
                              const SizedBox(height: 12),
                              FilledButton(
                                onPressed: _submit,
                                style: FilledButton.styleFrom(
                                  minimumSize: const Size.fromHeight(50),
                                ),
                                child: const Text('Submit answer'),
                              ),
                            ] else ...[
                              Text(
                                correct > 0
                                    ? '$correct correct answers · ${correct * 100} points'
                                    : 'Solve as many additions and subtractions as you can in 45 seconds.',
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  color: TablePlayColors.muted,
                                  height: 1.4,
                                ),
                              ),
                              const SizedBox(height: 18),
                              FilledButton.icon(
                                onPressed: _start,
                                icon: const Icon(Icons.play_arrow_rounded),
                                label: Text(
                                  correct > 0
                                      ? 'Play again'
                                      : 'Start challenge',
                                ),
                                style: FilledButton.styleFrom(
                                  backgroundColor: TablePlayColors.accent,
                                  minimumSize: const Size(210, 52),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}

class _GameStat extends StatelessWidget {
  const _GameStat({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label, value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: TablePlayColors.border),
        ),
        child: Column(
          children: [
            Icon(icon, size: 18, color: TablePlayColors.accent),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                value,
                style:
                    const TextStyle(fontSize: 17, fontWeight: FontWeight.w900),
              ),
            ),
            Text(
              label,
              style: const TextStyle(
                color: TablePlayColors.muted,
                fontSize: 8,
                fontWeight: FontWeight.w900,
                letterSpacing: 1,
              ),
            ),
          ],
        ),
      );
}

enum _SnakeDirection { up, down, left, right }

class SnakeGameV2 extends StatefulWidget {
  const SnakeGameV2({super.key, required this.game, required this.api});

  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<SnakeGameV2> createState() => _SnakeGameV2State();
}

class _SnakeGameV2State extends State<SnakeGameV2> with WidgetsBindingObserver {
  static const columns = 18;
  static const rows = 18;
  final Random random = Random();
  List<int> snake = [];
  _SnakeDirection direction = _SnakeDirection.right;
  _SnakeDirection queuedDirection = _SnakeDirection.right;
  Timer? timer;
  int food = 0, score = 0, best = 0, tickMilliseconds = 190;
  bool playing = false, gameOver = false, submitted = false;
  DateTime? startedAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _resetBoard();
  }

  void _resetBoard() {
    final center = (rows ~/ 2) * columns + columns ~/ 2;
    snake = [center, center - 1, center - 2];
    direction = _SnakeDirection.right;
    queuedDirection = direction;
    score = 0;
    tickMilliseconds = 190;
    gameOver = false;
    submitted = false;
    _placeFood();
  }

  void _start() {
    timer?.cancel();
    setState(() {
      _resetBoard();
      playing = true;
      startedAt = DateTime.now();
    });
    _runTimer();
  }

  void _runTimer() {
    timer?.cancel();
    timer = Timer.periodic(Duration(milliseconds: tickMilliseconds), (_) {
      if (mounted && playing) _tick();
    });
  }

  void _tick() {
    direction = queuedDirection;
    final head = snake.first;
    var x = head % columns, y = head ~/ columns;
    switch (direction) {
      case _SnakeDirection.up:
        y--;
        break;
      case _SnakeDirection.down:
        y++;
        break;
      case _SnakeDirection.left:
        x--;
        break;
      case _SnakeDirection.right:
        x++;
        break;
    }
    if (x < 0 || x >= columns || y < 0 || y >= rows) {
      _finish();
      return;
    }
    final next = y * columns + x;
    final eating = next == food;
    final collisionBody = eating ? snake : snake.take(snake.length - 1);
    if (collisionBody.contains(next)) {
      _finish();
      return;
    }
    setState(() {
      snake.insert(0, next);
      if (eating) {
        score += 10;
        if (score > best) best = score;
        _placeFood();
        if (score % 50 == 0 && tickMilliseconds > 95) {
          tickMilliseconds -= 12;
          _runTimer();
        }
      } else {
        snake.removeLast();
      }
    });
  }

  void _placeFood() {
    if (snake.length >= rows * columns) return;
    do {
      food = random.nextInt(rows * columns);
    } while (snake.contains(food));
  }

  void _turn(_SnakeDirection next) {
    final opposite = (direction == _SnakeDirection.up &&
            next == _SnakeDirection.down) ||
        (direction == _SnakeDirection.down && next == _SnakeDirection.up) ||
        (direction == _SnakeDirection.left && next == _SnakeDirection.right) ||
        (direction == _SnakeDirection.right && next == _SnakeDirection.left);
    if (!opposite && playing) queuedDirection = next;
  }

  void _finish() {
    timer?.cancel();
    if (!mounted) return;
    setState(() {
      playing = false;
      gameOver = true;
      if (score > best) best = score;
    });
    _submitResult();
  }

  Future<void> _submitResult() async {
    if (submitted) return;
    submitted = true;
    final duration =
        startedAt == null ? 0 : DateTime.now().difference(startedAt!).inSeconds;
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Player',
        'score': score,
        'duration_seconds': duration,
      });
    } catch (_) {
      // Game access is validated by the server. A disconnected score is not
      // queued because it may outlive the table's authorised game session.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && playing) _finish();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('${widget.game['name']}')),
        body: Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xffe9f2ed), TablePlayColors.canvas],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
          child: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(18),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: _SnakeStat(
                              label: 'SCORE',
                              value: '$score',
                              icon: Icons.bolt_rounded,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _SnakeStat(
                              label: 'BEST',
                              value: '$best',
                              icon: Icons.emoji_events_rounded,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _SnakeStat(
                              label: 'SPEED',
                              value:
                                  '${(190 / tickMilliseconds).toStringAsFixed(1)}×',
                              icon: Icons.speed_rounded,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      GestureDetector(
                        onPanEnd: (details) {
                          final velocity = details.velocity.pixelsPerSecond;
                          if (velocity.dx.abs() > velocity.dy.abs()) {
                            _turn(
                              velocity.dx > 0
                                  ? _SnakeDirection.right
                                  : _SnakeDirection.left,
                            );
                          } else {
                            _turn(
                              velocity.dy > 0
                                  ? _SnakeDirection.down
                                  : _SnakeDirection.up,
                            );
                          }
                        },
                        child: AspectRatio(
                          aspectRatio: 1,
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(24),
                            child: CustomPaint(
                              painter: _SnakeBoardPainter(
                                snake: snake,
                                food: food,
                                columns: columns,
                                rows: rows,
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 15),
                      AnimatedSwitcher(
                        duration: const Duration(milliseconds: 180),
                        child: playing
                            ? const Text(
                                'Swipe the board or use the controls',
                                key: ValueKey('playing'),
                                style: TextStyle(
                                  color: TablePlayColors.muted,
                                  fontWeight: FontWeight.w700,
                                ),
                              )
                            : FilledButton.icon(
                                key: const ValueKey('start'),
                                onPressed: _start,
                                icon: const Icon(Icons.play_arrow_rounded),
                                label: Text(
                                    gameOver ? 'Play again' : 'Start game'),
                                style: FilledButton.styleFrom(
                                  backgroundColor: TablePlayColors.accent,
                                  minimumSize: const Size(210, 52),
                                ),
                              ),
                      ),
                      if (gameOver) ...[
                        const SizedBox(height: 8),
                        Text(
                          score == 0
                              ? 'Watch the walls—one more try!'
                              : 'Score saved to this table session.',
                          style: const TextStyle(
                            color: TablePlayColors.muted,
                            fontSize: 11,
                          ),
                        ),
                      ],
                      const SizedBox(height: 12),
                      _SnakeControls(onTurn: _turn, enabled: playing),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}

class _SnakeStat extends StatelessWidget {
  const _SnakeStat({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label, value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: TablePlayColors.border),
        ),
        child: Column(
          children: [
            Icon(icon, color: TablePlayColors.accent, size: 18),
            const SizedBox(height: 4),
            Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
            ),
            Text(
              label,
              style: const TextStyle(
                color: TablePlayColors.muted,
                fontSize: 8,
                fontWeight: FontWeight.w900,
                letterSpacing: 1,
              ),
            ),
          ],
        ),
      );
}

class _SnakeControls extends StatelessWidget {
  const _SnakeControls({required this.onTurn, required this.enabled});

  final ValueChanged<_SnakeDirection> onTurn;
  final bool enabled;

  Widget button(IconData icon, _SnakeDirection direction) =>
      IconButton.filledTonal(
        onPressed: enabled ? () => onTurn(direction) : null,
        icon: Icon(icon),
        iconSize: 27,
        style: IconButton.styleFrom(minimumSize: const Size(54, 48)),
      );

  @override
  Widget build(BuildContext context) => Column(
        children: [
          button(Icons.keyboard_arrow_up_rounded, _SnakeDirection.up),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              button(Icons.keyboard_arrow_left_rounded, _SnakeDirection.left),
              const SizedBox(width: 56),
              button(Icons.keyboard_arrow_right_rounded, _SnakeDirection.right),
            ],
          ),
          button(Icons.keyboard_arrow_down_rounded, _SnakeDirection.down),
        ],
      );
}

class _SnakeBoardPainter extends CustomPainter {
  const _SnakeBoardPainter({
    required this.snake,
    required this.food,
    required this.columns,
    required this.rows,
  });

  final List<int> snake;
  final int food, columns, rows;

  @override
  void paint(Canvas canvas, Size size) {
    final cell = size.width / columns;
    canvas.drawRect(Offset.zero & size, Paint()..color = TablePlayColors.deep);
    final grid = Paint()
      ..color = Colors.white.withValues(alpha: .035)
      ..strokeWidth = .7;
    for (var i = 1; i < columns; i++) {
      canvas.drawLine(Offset(i * cell, 0), Offset(i * cell, size.height), grid);
    }
    for (var i = 1; i < rows; i++) {
      canvas.drawLine(Offset(0, i * cell), Offset(size.width, i * cell), grid);
    }
    Rect rectFor(int position, double inset) => Rect.fromLTWH(
          (position % columns) * cell + inset,
          (position ~/ columns) * cell + inset,
          cell - inset * 2,
          cell - inset * 2,
        );
    canvas.drawCircle(
      rectFor(food, cell * .19).center,
      cell * .31,
      Paint()..color = TablePlayColors.accent,
    );
    for (var i = snake.length - 1; i >= 0; i--) {
      final paint = Paint()
        ..color = i == 0 ? TablePlayColors.gold : const Color(0xff65c8a4);
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          rectFor(snake[i], cell * .09),
          Radius.circular(cell * .25),
        ),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _SnakeBoardPainter oldDelegate) => true;
}

class TicTacToeV2 extends StatefulWidget {
  const TicTacToeV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<TicTacToeV2> createState() => _TicTacToeV2State();
}

class _TicTacToeV2State extends State<TicTacToeV2> {
  List<String> cells = List.filled(9, '');
  String turn = 'X', message = 'Player X turn';
  void play(int index) {
    if (cells[index].isNotEmpty ||
        message.contains('wins') ||
        message == 'Draw') {
      return;
    }
    setState(() {
      cells[index] = turn;
      final winner = check();
      if (winner != null) {
        message = 'Player $winner wins';
        submit(winner);
      } else if (!cells.contains('')) {
        message = 'Draw';
      } else {
        turn = turn == 'X' ? 'O' : 'X';
        message = 'Player $turn turn';
      }
    });
  }

  String? check() {
    for (final line in [
      [0, 1, 2],
      [3, 4, 5],
      [6, 7, 8],
      [0, 3, 6],
      [1, 4, 7],
      [2, 5, 8],
      [0, 4, 8],
      [2, 4, 6],
    ]) {
      if (cells[line[0]].isNotEmpty &&
          cells[line[0]] == cells[line[1]] &&
          cells[line[1]] == cells[line[2]]) {
        return cells[line[0]];
      }
    }
    return null;
  }

  Future<void> submit(String winner) async {
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Player $winner',
        'player_position': winner == 'X' ? 1 : 2,
        'score': 1,
      });
    } catch (_) {}
  }

  void reset() => setState(() {
        cells = List.filled(9, '');
        turn = 'X';
        message = 'Player X turn';
      });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.game['name'])),
        body: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(22),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 18, vertical: 11),
                  decoration: BoxDecoration(
                    color: (turn == 'X'
                            ? TablePlayColors.deep
                            : TablePlayColors.accent)
                        .withValues(alpha: .1),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Text(
                    message,
                    style: TextStyle(
                      color: turn == 'X'
                          ? TablePlayColors.deep
                          : TablePlayColors.accent,
                      fontWeight: FontWeight.w900,
                      fontSize: 17,
                    ),
                  ),
                ),
                const SizedBox(height: 22),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 390),
                  child: AspectRatio(
                    aspectRatio: 1,
                    child: GridView.builder(
                      physics: const NeverScrollableScrollPhysics(),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 3,
                        mainAxisSpacing: 8,
                        crossAxisSpacing: 8,
                      ),
                      itemCount: 9,
                      itemBuilder: (_, index) => Material(
                        color: cells[index].isEmpty
                            ? Colors.white
                            : (cells[index] == 'X'
                                    ? TablePlayColors.deep
                                    : TablePlayColors.accent)
                                .withValues(alpha: .1),
                        borderRadius: BorderRadius.circular(17),
                        child: InkWell(
                          onTap: () => play(index),
                          borderRadius: BorderRadius.circular(17),
                          child: Center(
                            child: Text(
                              cells[index],
                              style: TextStyle(
                                fontSize: 54,
                                fontWeight: FontWeight.w900,
                                color: cells[index] == 'X'
                                    ? TablePlayColors.deep
                                    : TablePlayColors.accent,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                OutlinedButton.icon(
                  onPressed: reset,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('New game'),
                ),
              ],
            ),
          ),
        ),
      );
}
