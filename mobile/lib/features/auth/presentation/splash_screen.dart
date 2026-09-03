import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widget_previews.dart';

import '../../../app/theme/blue_theme.dart';

/// App-launch splash — Concept A "Rise & Settle" from Blue Splash A:
/// penguin rises, settles with a soft greeting nod, then Blue resolves
/// before handing off to auth / home.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, this.onFinished});

  /// Called after the greeting animation finishes.
  final VoidCallback? onFinished;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  static const _navy = Color(0xFF150253);
  static const _duration = Duration(milliseconds: 1800);

  late final AnimationController _controller;
  bool _finished = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _duration)
      ..addStatusListener((status) {
        if (status == AnimationStatus.completed) _finish();
      });

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (MediaQuery.disableAnimationsOf(context)) {
        _controller.value = 1;
        _finish();
        return;
      }
      _controller.forward();
    });
  }

  void _finish() {
    if (_finished || !mounted) return;
    _finished = true;
    widget.onFinished?.call();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
        systemNavigationBarColor: Colors.white,
        systemNavigationBarIconBrightness: Brightness.dark,
      ),
      child: Scaffold(
        backgroundColor: Colors.white,
        body: AnimatedBuilder(
          animation: _controller,
          builder: (context, _) {
            final t = _controller.value * (_duration.inMilliseconds / 1000);
            return _ConceptA(t: t, navy: _navy);
          },
        ),
      ),
    );
  }
}

class _ConceptA extends StatelessWidget {
  const _ConceptA({
    required this.t,
    required this.navy,
  });

  final double t;
  final Color navy;

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final scale = (size.height / 940).clamp(0.72, 1.15);

    final rise = _soft(t, from: 168, to: 0, start: 0.04, end: 0.85) * scale;
    final fade = _soft(t, from: 0, to: 1, start: 0.04, end: 0.55);
    final squash = _seq(t, [0.48, 0.72, 1.0], [1.035, 0.995, 1]);
    final nod = _waveAngle(t, start: 0.62, dur: 0.8, cycles: 1, amp: 2.6);
    final tilt =
        _seq(t, [0.48, 0.8, 1.25, 1.55], [0, -1.6, -1.6, 0]) + nod;
    final lift =
        _waveAngle(t, start: 0.62, dur: 0.8, cycles: 0.5, amp: 5).abs() *
            scale;
    final breathe = 1 + 0.008 * math.sin(_clamp(t - 1.15, 0, 9) * 1.2);
    final tag = _soft(t, from: 0, to: 1, start: 0.85, end: 1.3);

    final markSize = 46 * scale;
    final mascotH = 92 * scale;
    final mascotW = mascotH * (192.36 / 325.28);
    final taglineSize = 13.5 * scale;

    return Stack(
      children: [
        Positioned(
          left: 0,
          right: 0,
          top: size.height * 0.30,
          child: Opacity(
            opacity: fade,
            child: Transform.translate(
              offset: Offset(0, rise),
              child: Transform.scale(
                scale: breathe,
                alignment: Alignment.bottomCenter,
                child: Transform.translate(
                  offset: Offset(0, -lift),
                  child: Transform.rotate(
                    angle: tilt * math.pi / 180,
                    alignment: Alignment.bottomCenter,
                    child: Transform(
                      alignment: Alignment.bottomCenter,
                      transform: Matrix4.diagonal3Values(
                        squash,
                        2 - squash,
                        1,
                      ),
                      child: Image.asset(
                        'assets/brand/penguin.png',
                        width: mascotW,
                        height: mascotH,
                        fit: BoxFit.contain,
                        filterQuality: FilterQuality.high,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        Positioned(
          left: 0,
          right: 0,
          top: size.height * 0.30 + mascotH + 20 * scale,
          child: Opacity(
            opacity: tag,
            child: Transform.translate(
              offset: Offset(0, (1 - tag) * 18 * scale),
              child: Text(
                'Blue',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontFamily: BlueFonts.poppins,
                  fontWeight: FontWeight.w800,
                  fontSize: markSize,
                  height: 1,
                  letterSpacing: markSize * -0.02,
                  color: navy,
                ),
              ),
            ),
          ),
        ),
        Positioned(
          left: 24,
          right: 24,
          bottom: 56 * scale + MediaQuery.paddingOf(context).bottom,
          child: Opacity(
            opacity: tag * 0.95,
            child: Text(
              'POWERED BY PENGUIN',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontWeight: FontWeight.w400,
                fontSize: taglineSize,
                letterSpacing: taglineSize * 0.28,
                color: navy.withValues(alpha: 0.45),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

double _clamp(double v, double min, double max) =>
    v < min ? min : (v > max ? max : v);

double _soft(
  double t, {
  required double from,
  required double to,
  required double start,
  required double end,
}) {
  if (t <= start) return from;
  if (t >= end) return to;
  final p = Curves.easeInOutSine.transform((t - start) / (end - start));
  return from + (to - from) * p;
}

double _seq(double t, List<double> stops, List<double> values) {
  assert(stops.length == values.length && stops.length >= 2);
  if (t <= stops.first) return values.first;
  if (t >= stops.last) return values.last;
  for (var i = 0; i < stops.length - 1; i++) {
    if (t <= stops[i + 1]) {
      final span = stops[i + 1] - stops[i];
      final p = span <= 0
          ? 1.0
          : Curves.easeInOutSine.transform((t - stops[i]) / span);
      return values[i] + (values[i + 1] - values[i]) * p;
    }
  }
  return values.last;
}

double _waveAngle(
  double t, {
  required double start,
  required double dur,
  required double cycles,
  required double amp,
}) {
  if (t <= start) return 0;
  final p = _clamp((t - start) / dur, 0, 1);
  final bell = math.sin(math.pi * p);
  return math.sin(p * cycles * math.pi * 2) * amp * bell;
}

@Preview(name: 'Splash', group: 'Auth', size: Size(390, 844))
Widget splashScreenPreview() {
  return const SplashScreen(onFinished: _previewNoop);
}

void _previewNoop() {}
