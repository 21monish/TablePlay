import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../theme/tableplay_theme.dart';

Future<void> _saveResult(
    ApiClient api, Map<String, dynamic> game, int player, int score) async {
  try {
    await api.post('/games/results', {
      'game_id': game['id'],
      'player_name': 'Player $player',
      'player_position': player,
      'score': max(0, score),
    });
  } catch (_) {}
}

class WordScrambleV2 extends StatefulWidget {
  const WordScrambleV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<WordScrambleV2> createState() => _WordScrambleV2State();
}

class _WordScrambleV2State extends State<WordScrambleV2> {
  static const words = [
    'PIZZA',
    'BURGER',
    'NOODLES',
    'COFFEE',
    'TABLE',
    'WAITER',
    'KITCHEN',
    'DESSERT'
  ];
  final random = Random();
  final answer = TextEditingController();
  late String word, scrambled;
  int score = 0, round = 1;
  String feedback = 'Unscramble the restaurant word';

  @override
  void initState() {
    super.initState();
    _next();
  }

  void _next() {
    word = words[random.nextInt(words.length)];
    final letters = word.split('');
    do {
      letters.shuffle(random);
    } while (letters.join() == word && word.length > 1);
    scrambled = letters.join('  ');
    answer.clear();
  }

  Future<void> _submit() async {
    if (answer.text.trim().toUpperCase() != word) {
      setState(() => feedback = 'Try again — the letters are all there.');
      return;
    }
    score += 100;
    if (round >= 8) {
      await _saveResult(widget.api, widget.game, 1, score);
      if (mounted) setState(() => feedback = 'Complete! Final score: $score');
      return;
    }
    setState(() {
      round++;
      feedback = 'Correct! Next word';
      _next();
    });
  }

  @override
  void dispose() {
    answer.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _GameShell(
        title: '${widget.game['name']}',
        child: Column(children: [
          _ScoreHeader(items: [('ROUND', '$round/8'), ('SCORE', '$score')]),
          const SizedBox(height: 24),
          Text(scrambled,
              key: const Key('scrambled-word'),
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 34,
                  fontWeight: FontWeight.w900,
                  color: TablePlayColors.deep)),
          const SizedBox(height: 10),
          Text(feedback,
              textAlign: TextAlign.center,
              style: const TextStyle(color: TablePlayColors.muted)),
          const SizedBox(height: 20),
          TextField(
              controller: answer,
              textCapitalization: TextCapitalization.characters,
              textAlign: TextAlign.center,
              onSubmitted: (_) => _submit(),
              decoration: const InputDecoration(labelText: 'Your answer')),
          const SizedBox(height: 12),
          FilledButton(
              key: const Key('word-submit'),
              onPressed: _submit,
              child: const Text('Check word')),
        ]),
      );
}

class ReactionDuelV2 extends StatefulWidget {
  const ReactionDuelV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<ReactionDuelV2> createState() => _ReactionDuelV2State();
}

class _ReactionDuelV2State extends State<ReactionDuelV2> {
  final random = Random();
  Timer? timer;
  bool ready = false, waiting = false;
  List<int> wins = [0, 0];
  String message = 'Press Start, then wait for GO!';

  void _start() {
    timer?.cancel();
    setState(() {
      waiting = true;
      ready = false;
      message = 'Wait…';
    });
    timer = Timer(Duration(milliseconds: 900 + random.nextInt(1800)), () {
      if (mounted) {
        setState(() {
          ready = true;
          waiting = false;
          message = 'GO!';
        });
      }
    });
  }

  Future<void> _tap(int player) async {
    if (waiting) {
      timer?.cancel();
      setState(() {
        waiting = false;
        message = 'Player $player tapped too early';
      });
      return;
    }
    if (!ready) return;
    wins[player - 1]++;
    setState(() {
      ready = false;
      message = 'Player $player wins the round';
    });
    if (wins[player - 1] >= 5) {
      await _saveResult(widget.api, widget.game, player, 1000);
    }
  }

  void _reset() => setState(() {
        timer?.cancel();
        wins = [0, 0];
        ready = false;
        waiting = false;
        message = 'Press Start, then wait for GO!';
      });
  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _GameShell(
      title: '${widget.game['name']}',
      child: Column(children: [
        _ScoreHeader(items: [
          ('PLAYER 1', '${wins[0]}/5'),
          ('PLAYER 2', '${wins[1]}/5')
        ]),
        const SizedBox(height: 18),
        AnimatedContainer(
            duration: const Duration(milliseconds: 160),
            height: 110,
            alignment: Alignment.center,
            decoration: BoxDecoration(
                color: ready ? Colors.green : TablePlayColors.deep,
                borderRadius: BorderRadius.circular(22)),
            child: Text(message,
                key: const Key('reaction-status'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 24,
                    fontWeight: FontWeight.w900))),
        const SizedBox(height: 16),
        Row(children: [
          Expanded(
              child: FilledButton(
                  key: const Key('reaction-player-1'),
                  onPressed: () => _tap(1),
                  child: const Text('PLAYER 1 TAP'))),
          const SizedBox(width: 12),
          Expanded(
              child: FilledButton(
                  key: const Key('reaction-player-2'),
                  onPressed: () => _tap(2),
                  child: const Text('PLAYER 2 TAP'))),
        ]),
        const SizedBox(height: 12),
        OutlinedButton.icon(
            onPressed: wins.any((value) => value >= 5) ? _reset : _start,
            icon: const Icon(Icons.flash_on_rounded),
            label: Text(
                wins.any((value) => value >= 5) ? 'New duel' : 'Start round')),
      ]));
}

class AirHockeyV2 extends StatefulWidget {
  const AirHockeyV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<AirHockeyV2> createState() => _AirHockeyV2State();
}

class _AirHockeyV2State extends State<AirHockeyV2> {
  final random = Random();
  List<int> scores = [0, 0];
  int turn = 0;
  String message = 'Player 1 attacks';

  Future<void> _shoot(int player) async {
    if (player - 1 != turn || scores.any((score) => score >= 5)) return;
    final goal = random.nextInt(100) < 58;
    if (goal) scores[player - 1]++;
    final won = scores[player - 1] >= 5;
    setState(() {
      message = goal ? 'GOAL for Player $player!' : 'Saved!';
      if (!won) turn = 1 - turn;
    });
    if (won) {
      await _saveResult(widget.api, widget.game, player,
          1500 - scores.reduce((a, b) => a + b) * 20);
    }
  }

  void _reset() => setState(() {
        scores = [0, 0];
        turn = 0;
        message = 'Player 1 attacks';
      });

  @override
  Widget build(BuildContext context) => _GameShell(
      title: '${widget.game['name']}',
      child: Column(children: [
        _ScoreHeader(items: [
          ('PLAYER 1', '${scores[0]}'),
          ('PLAYER 2', '${scores[1]}')
        ]),
        const SizedBox(height: 18),
        Container(
            height: 230,
            decoration: BoxDecoration(
                color: const Color(0xffd9f1f5),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: TablePlayColors.deep, width: 5)),
            child: Stack(alignment: Alignment.center, children: [
              Container(width: 3, color: Colors.white),
              Container(
                  width: 70,
                  height: 70,
                  decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 3))),
              const Icon(Icons.circle, color: Colors.black87, size: 34),
              const Positioned(
                  left: 20,
                  child: Icon(Icons.sports_hockey_rounded,
                      color: TablePlayColors.accent, size: 52)),
              const Positioned(
                  right: 20,
                  child: Icon(Icons.sports_hockey_rounded,
                      color: TablePlayColors.deep, size: 52)),
            ])),
        const SizedBox(height: 12),
        Text(message,
            key: const Key('hockey-status'),
            style: const TextStyle(fontWeight: FontWeight.w900)),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(
              child: FilledButton(
                  key: const Key('hockey-player-1'),
                  onPressed: turn == 0 ? () => _shoot(1) : null,
                  child: const Text('P1 SHOOT'))),
          const SizedBox(width: 12),
          Expanded(
              child: FilledButton(
                  key: const Key('hockey-player-2'),
                  onPressed: turn == 1 ? () => _shoot(2) : null,
                  child: const Text('P2 SHOOT'))),
        ]),
        const SizedBox(height: 10),
        OutlinedButton(onPressed: _reset, child: const Text('New match')),
      ]));
}

class QuizBattleV2 extends StatefulWidget {
  const QuizBattleV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<QuizBattleV2> createState() => _QuizBattleV2State();
}

class _QuizBattleV2State extends State<QuizBattleV2> {
  static const questions = [
    (
      'Which fruit is used in guacamole?',
      ['Apple', 'Avocado', 'Mango', 'Grape'],
      1
    ),
    ('How many days are in a leap year?', ['364', '365', '366', '367'], 2),
    (
      'Which planet is called the Red Planet?',
      ['Mars', 'Venus', 'Earth', 'Jupiter'],
      0
    ),
    ('What is H2O commonly called?', ['Salt', 'Sugar', 'Water', 'Air'], 2),
    (
      'Which animal is the fastest on land?',
      ['Lion', 'Cheetah', 'Horse', 'Tiger'],
      1
    ),
    (
      'How many sides does a hexagon have?',
      ['Five', 'Six', 'Seven', 'Eight'],
      1
    ),
    (
      'Which is the largest ocean?',
      ['Indian', 'Atlantic', 'Pacific', 'Arctic'],
      2
    ),
    (
      'Which ingredient makes bread rise?',
      ['Pepper', 'Yeast', 'Oil', 'Rice'],
      1
    ),
  ];
  List<int> scores = [0, 0, 0, 0];
  int turn = 0, question = 0;
  bool complete = false;

  Future<void> _answer(int choice) async {
    if (complete) return;
    if (choice == questions[question].$3) scores[turn]++;
    question++;
    if (question >= questions.length) {
      complete = true;
      final best = scores.reduce(max), winner = scores.indexOf(best) + 1;
      await _saveResult(widget.api, widget.game, winner, best * 250);
    } else {
      turn = (turn + 1) % 4;
    }
    if (mounted) setState(() {});
  }

  void _reset() => setState(() {
        scores = [0, 0, 0, 0];
        turn = 0;
        question = 0;
        complete = false;
      });

  @override
  Widget build(BuildContext context) => _GameShell(
      title: '${widget.game['name']}',
      child: Column(children: [
        _ScoreHeader(
            items: List.generate(4, (i) => ('P${i + 1}', '${scores[i]}'))),
        const SizedBox(height: 20),
        Text(
            complete
                ? 'Quiz complete!'
                : 'Player ${turn + 1} · Question ${question + 1}/${questions.length}',
            key: const Key('quiz-status'),
            style: const TextStyle(fontWeight: FontWeight.w900)),
        const SizedBox(height: 14),
        Text(
            complete
                ? 'Winner: Player ${scores.indexOf(scores.reduce(max)) + 1}'
                : questions[question].$1,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
        const SizedBox(height: 18),
        if (!complete)
          ...List.generate(
              4,
              (i) => Padding(
                  padding: const EdgeInsets.only(bottom: 9),
                  child: OutlinedButton(
                      key: Key('quiz-answer-$i'),
                      onPressed: () => _answer(i),
                      style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(48)),
                      child: Text(questions[question].$2[i])))),
        if (complete)
          FilledButton.icon(
              onPressed: _reset,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('New quiz')),
      ]));
}

class TapEliminationV2 extends StatefulWidget {
  const TapEliminationV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<TapEliminationV2> createState() => _TapEliminationV2State();
}

class _TapEliminationV2State extends State<TapEliminationV2> {
  final random = Random();
  List<int> points = [0, 0, 0, 0];
  int target = 0, round = 1;
  bool started = false, complete = false;

  void _start() => setState(() {
        started = true;
        target = random.nextInt(4);
      });
  Future<void> _tap(int player) async {
    if (!started || complete || player != target) return;
    points[player]++;
    if (round >= 12) {
      complete = true;
      final best = points.reduce(max), winner = points.indexOf(best) + 1;
      await _saveResult(widget.api, widget.game, winner, best * 200);
    } else {
      round++;
      target = random.nextInt(4);
    }
    if (mounted) setState(() {});
  }

  void _reset() => setState(() {
        points = [0, 0, 0, 0];
        target = 0;
        round = 1;
        started = false;
        complete = false;
      });

  @override
  Widget build(BuildContext context) => _GameShell(
      title: '${widget.game['name']}',
      child: Column(children: [
        _ScoreHeader(
            items: List.generate(4, (i) => ('P${i + 1}', '${points[i]}'))),
        const SizedBox(height: 16),
        Text(
            complete
                ? 'Player ${points.indexOf(points.reduce(max)) + 1} wins!'
                : (started
                    ? 'Player ${target + 1}, TAP NOW!'
                    : 'Watch for your player number'),
            key: const Key('tap-elimination-status'),
            style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w900)),
        const SizedBox(height: 16),
        GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: 4,
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: 1.8),
            itemBuilder: (_, i) => FilledButton(
                key: Key('elimination-player-$i'),
                onPressed: () => _tap(i),
                style: FilledButton.styleFrom(
                    backgroundColor: started && target == i
                        ? [
                            Colors.red,
                            Colors.blue,
                            Colors.green,
                            Colors.purple
                          ][i]
                        : Colors.black26),
                child: Text('PLAYER ${i + 1}',
                    style: const TextStyle(
                        fontSize: 18, fontWeight: FontWeight.w900)))),
        const SizedBox(height: 14),
        OutlinedButton.icon(
            onPressed: complete ? _reset : _start,
            icon: const Icon(Icons.bolt_rounded),
            label: Text(
                complete ? 'New game' : (started ? 'Next signal' : 'Start'))),
      ]));
}

class LudoMiniV2 extends StatefulWidget {
  const LudoMiniV2({super.key, required this.game, required this.api});
  final Map<String, dynamic> game;
  final ApiClient api;
  @override
  State<LudoMiniV2> createState() => _LudoMiniV2State();
}

class _LudoMiniV2State extends State<LudoMiniV2> {
  final random = Random();
  List<int> positions = [0, 0, 0, 0];
  int turn = 0, lastRoll = 0, winner = -1;

  Future<void> _roll() async {
    if (winner >= 0) return;
    final value = random.nextInt(6) + 1, player = turn;
    setState(() {
      lastRoll = value;
      if (positions[player] + value <= 24) {
        positions[player] += value;
      }
      if (positions[player] == 24) {
        winner = player;
      } else if (value != 6) {
        turn = (turn + 1) % 4;
      }
    });
    if (winner >= 0) {
      await _saveResult(widget.api, widget.game, winner + 1, 1800);
    }
  }

  void _reset() => setState(() {
        positions = [0, 0, 0, 0];
        turn = 0;
        lastRoll = 0;
        winner = -1;
      });

  @override
  Widget build(BuildContext context) => _GameShell(
      title: '${widget.game['name']}',
      child: Column(children: [
        Text(
            winner >= 0
                ? 'Player ${winner + 1} reached home!'
                : 'Player ${turn + 1} rolls',
            key: const Key('ludo-status'),
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        Text(
            lastRoll == 0
                ? 'Roll an exact number to reach 24.'
                : 'Dice: $lastRoll${lastRoll == 6 ? ' · roll again' : ''}',
            style: const TextStyle(color: TablePlayColors.muted)),
        const SizedBox(height: 20),
        ...List.generate(
            4,
            (i) => ListTile(
                leading: CircleAvatar(child: Text('${i + 1}')),
                title: LinearProgressIndicator(
                    value: positions[i] / 24,
                    minHeight: 13,
                    borderRadius: BorderRadius.circular(8)),
                trailing: Text('${positions[i]}/24',
                    key: Key('ludo-score-$i'),
                    style: const TextStyle(fontWeight: FontWeight.w900)))),
        const SizedBox(height: 14),
        FilledButton.icon(
            key: const Key('ludo-roll'),
            onPressed: winner < 0 ? _roll : null,
            icon: const Icon(Icons.casino_rounded),
            label: const Text('Roll dice')),
        const SizedBox(height: 8),
        OutlinedButton(onPressed: _reset, child: const Text('New game')),
      ]));
}

class _GameShell extends StatelessWidget {
  const _GameShell({required this.title, required this.child});
  final String title;
  final Widget child;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(title)),
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
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: Container(
                    padding: const EdgeInsets.all(22),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(24),
                      border: Border.all(color: TablePlayColors.border),
                    ),
                    child: child,
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}

class _ScoreHeader extends StatelessWidget {
  const _ScoreHeader({required this.items});
  final List<(String, String)> items;
  @override
  Widget build(BuildContext context) => Row(
      children: items.indexed
          .map((entry) => Expanded(
              child: Container(
                  margin: EdgeInsets.only(
                      right: entry.$1 == items.length - 1 ? 0 : 8),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  decoration: BoxDecoration(
                      color: TablePlayColors.canvas,
                      borderRadius: BorderRadius.circular(14)),
                  child: Column(children: [
                    Text(entry.$2.$1,
                        style: const TextStyle(
                            fontSize: 9,
                            color: TablePlayColors.muted,
                            fontWeight: FontWeight.w900)),
                    Text(entry.$2.$2,
                        style: const TextStyle(
                            fontSize: 18, fontWeight: FontWeight.w900))
                  ]))))
          .toList());
}
