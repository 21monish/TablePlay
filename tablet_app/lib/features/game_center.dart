import 'dart:async';
import 'package:flutter/material.dart';
import '../core/api_client.dart';

class GameCenter extends StatefulWidget {
  const GameCenter({super.key, required this.api, required this.access});
  final ApiClient api;
  final Map<String, dynamic> access;
  @override
  State<GameCenter> createState() => _GameCenterState();
}

class _GameCenterState extends State<GameCenter> {
  List<dynamic> games = [];
  @override
  void initState() {
    super.initState();
    widget.api.get('/games').then((value) {
      if (mounted) setState(() => games = value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final unlocked = widget.access['unlocked'] == true,
        seconds = (widget.access['remaining_seconds'] as num?)?.toInt() ?? 0;
    if (!unlocked) return const _Locked();
    return ListView(padding: const EdgeInsets.all(20), children: [
      Text(
          'Games unlocked · ${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}',
          style: Theme.of(context).textTheme.headlineSmall),
      const SizedBox(height: 16),
      ...games.map((raw) {
        final game = Map<String, dynamic>.from(raw);
        return Card(
            child: ListTile(
                leading: Icon(
                    game['player_mode'] == 'two'
                        ? Icons.grid_3x3
                        : Icons.touch_app,
                    size: 38),
                title: Text(game['name']),
                subtitle: Text(
                    game['description'] ?? '${game['player_mode']} player'),
                trailing: FilledButton(
                    onPressed: () => open(game), child: const Text('Play'))));
      })
    ]);
  }

  void open(Map<String, dynamic> game) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => game['slug'] == 'tic-tac-toe'
            ? TicTacToe(game: game, api: widget.api)
            : TapChallenge(game: game, api: widget.api)));
  }
}

class _Locked extends StatelessWidget {
  const _Locked();
  @override
  Widget build(BuildContext context) => Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.lock, size: 80),
        const SizedBox(height: 12),
        Text('Games are locked',
            style: Theme.of(context).textTheme.headlineMedium),
        const Text('The counter unlocks games after confirming an order.')
      ]));
}

class TapChallenge extends StatefulWidget {
  const TapChallenge({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<TapChallenge> createState() => _TapChallengeState();
}

class _TapChallengeState extends State<TapChallenge> {
  int taps = 0, left = 15;
  bool playing = false;
  Timer? timer;
  void start() {
    setState(() {
      taps = 0;
      left = 15;
      playing = true;
    });
    timer = Timer.periodic(const Duration(seconds: 1), (t) {
      setState(() => left--);
      if (left <= 0) {
        t.cancel();
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
        'duration_seconds': 15
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
      body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('$left',
            style: const TextStyle(fontSize: 48, fontWeight: FontWeight.bold)),
        Text('$taps taps', style: Theme.of(context).textTheme.headlineMedium),
        const SizedBox(height: 24),
        playing
            ? GestureDetector(
                onTap: () => setState(() => taps++),
                child: Container(
                    width: 220,
                    height: 220,
                    decoration: const BoxDecoration(
                        shape: BoxShape.circle, color: Color(0xffd8552f)),
                    alignment: Alignment.center,
                    child: const Text('TAP!',
                        style: TextStyle(
                            color: Colors.white,
                            fontSize: 42,
                            fontWeight: FontWeight.bold))))
            : FilledButton(
                onPressed: start,
                child: const Padding(
                    padding: EdgeInsets.all(18),
                    child: Text('Start 15-second challenge')))
      ])));
}

class TicTacToe extends StatefulWidget {
  const TicTacToe({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<TicTacToe> createState() => _TicTacToeState();
}

class _TicTacToeState extends State<TicTacToe> {
  List<String> cells = List.filled(9, '');
  String turn = 'X', message = 'Player X turn';
  void play(int i) {
    if (cells[i].isNotEmpty || message.contains('wins') || message == 'Draw') {
      return;
    }
    setState(() {
      cells[i] = turn;
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
      [2, 4, 6]
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
        'score': 1
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
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(message, style: Theme.of(context).textTheme.headlineMedium),
        const SizedBox(height: 18),
        SizedBox(
            width: 360,
            height: 360,
            child: GridView.builder(
                physics: const NeverScrollableScrollPhysics(),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 3),
                itemCount: 9,
                itemBuilder: (_, i) => Card(
                    child: InkWell(
                        onTap: () => play(i),
                        child: Center(
                            child: Text(cells[i],
                                style: const TextStyle(
                                    fontSize: 58,
                                    fontWeight: FontWeight.bold))))))),
        const SizedBox(height: 16),
        OutlinedButton(onPressed: reset, child: const Text('New game'))
      ])));
}
