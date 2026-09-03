import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

abstract final class BlueMotion {
  static const curve = Cubic(0.22, 0.61, 0.36, 1);
  static const exitCurve = Cubic(0.4, 0.0, 1.0, 1.0);
  static const page = Duration(milliseconds: 380);
  static const sheet = Duration(milliseconds: 420);
  static const sheetOut = Duration(milliseconds: 280);
  static const press = Duration(milliseconds: 120);
  static const tile = Duration(milliseconds: 180);
  static const snap = Duration(milliseconds: 200);
  static const rise = Duration(milliseconds: 320);
  static const enter = Duration(milliseconds: 520);
  static const tick = Duration(milliseconds: 160);
  static const shimmer = Duration(milliseconds: 1400);

  static void tap() => HapticFeedback.selectionClick();

  static void warn() => HapticFeedback.mediumImpact();

  static Duration of(BuildContext context, Duration duration) {
    return MediaQuery.disableAnimationsOf(context) ? Duration.zero : duration;
  }
}

/// Scales down slightly on press so every tap has motion.
class BluePressable extends StatefulWidget {
  const BluePressable({
    super.key,
    required this.child,
    required this.onPressed,
    this.enabled = true,
    this.scale = 0.97,
    this.haptic = true,
    this.duration = BlueMotion.press,
  });

  final Widget child;
  final VoidCallback? onPressed;
  final bool enabled;
  final double scale;
  final bool haptic;
  final Duration duration;

  @override
  State<BluePressable> createState() => _BluePressableState();
}

class _BluePressableState extends State<BluePressable> {
  bool _down = false;

  bool get _canPress => widget.enabled && widget.onPressed != null;

  void _setDown(bool value) {
    if (!mounted || _down == value) return;
    setState(() => _down = value);
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: _canPress ? (_) => _setDown(true) : null,
      onTapUp: _canPress ? (_) => _setDown(false) : null,
      onTapCancel: _canPress ? () => _setDown(false) : null,
      onTap: _canPress
          ? () {
              if (widget.haptic) BlueMotion.tap();
              widget.onPressed!();
            }
          : null,
      child: AnimatedScale(
        scale: _down ? widget.scale : 1,
        duration: BlueMotion.of(context, widget.duration),
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}

/// One-shot fade + rise used for staggered screen entrances.
class BlueEnter extends StatefulWidget {
  const BlueEnter({
    super.key,
    required this.child,
    this.delay = Duration.zero,
    this.offset = const Offset(0, 0.045),
    this.duration = BlueMotion.enter,
  });

  final Widget child;
  final Duration delay;
  final Offset offset;
  final Duration duration;

  @override
  State<BlueEnter> createState() => _BlueEnterState();
}

class _BlueEnterState extends State<BlueEnter>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;

  @override
  void initState() {
    super.initState();
    final total = widget.delay + widget.duration;
    _controller = AnimationController(vsync: this, duration: total);
    final start = total.inMilliseconds == 0
        ? 0.0
        : widget.delay.inMilliseconds / total.inMilliseconds;
    final curved = CurvedAnimation(
      parent: _controller,
      curve: Interval(start.clamp(0.0, 0.9), 1, curve: BlueMotion.curve),
    );
    _fade = curved;
    _slide = Tween<Offset>(
      begin: widget.offset,
      end: Offset.zero,
    ).animate(curved);
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: SlideTransition(position: _slide, child: widget.child),
    );
  }
}

/// Staggered rise for rows inside an opening list / sheet.
class BlueListReveal extends StatelessWidget {
  const BlueListReveal({
    super.key,
    required this.index,
    required this.child,
    this.animate = true,
  });

  final int index;
  final Widget child;
  final bool animate;

  @override
  Widget build(BuildContext context) {
    if (!animate || MediaQuery.disableAnimationsOf(context)) return child;
    final delayMs = (index * 42).clamp(0, 220);
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: Duration(milliseconds: 320 + delayMs),
      curve: BlueMotion.curve,
      builder: (context, t, child) {
        return Opacity(
          opacity: t,
          child: Transform.translate(
            offset: Offset(0, 14 * (1 - t)),
            child: child,
          ),
        );
      },
      child: child,
    );
  }
}

class BluePageTransitionsBuilder extends PageTransitionsBuilder {
  const BluePageTransitionsBuilder();

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    if (MediaQuery.disableAnimationsOf(context)) return child;
    return _BluePageTransition(
      animation: animation,
      secondaryAnimation: secondaryAnimation,
      child: child,
    );
  }
}

class _BluePageTransition extends StatefulWidget {
  const _BluePageTransition({
    required this.animation,
    required this.secondaryAnimation,
    required this.child,
  });

  final Animation<double> animation;
  final Animation<double> secondaryAnimation;
  final Widget child;

  @override
  State<_BluePageTransition> createState() => _BluePageTransitionState();
}

class _BluePageTransitionState extends State<_BluePageTransition> {
  late CurvedAnimation _incoming;
  late CurvedAnimation _outgoing;

  static final _inSlide = Tween<Offset>(
    begin: const Offset(0.16, 0),
    end: Offset.zero,
  );
  static final _outSlide = Tween<Offset>(
    begin: Offset.zero,
    end: const Offset(-0.12, 0),
  );
  static final _outFade = Tween<double>(begin: 1, end: 0.78);

  @override
  void initState() {
    super.initState();
    _bind();
  }

  @override
  void didUpdateWidget(_BluePageTransition oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.animation != widget.animation ||
        oldWidget.secondaryAnimation != widget.secondaryAnimation) {
      _incoming.dispose();
      _outgoing.dispose();
      _bind();
    }
  }

  void _bind() {
    _incoming = CurvedAnimation(
      parent: widget.animation,
      curve: BlueMotion.curve,
      reverseCurve: BlueMotion.exitCurve,
    );
    _outgoing = CurvedAnimation(
      parent: widget.secondaryAnimation,
      curve: BlueMotion.curve,
      reverseCurve: BlueMotion.exitCurve,
    );
  }

  @override
  void dispose() {
    _incoming.dispose();
    _outgoing.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SlideTransition(
      position: _outSlide.animate(_outgoing),
      child: FadeTransition(
        opacity: _outFade.animate(_outgoing),
        child: SlideTransition(
          position: _inSlide.animate(_incoming),
          child: FadeTransition(opacity: _incoming, child: widget.child),
        ),
      ),
    );
  }
}

class BluePageRoute<T> extends MaterialPageRoute<T> {
  BluePageRoute({required super.builder, super.settings});

  @override
  Duration get transitionDuration => BlueMotion.page;

  @override
  Duration get reverseTransitionDuration => BlueMotion.sheetOut;
}

/// Cross-fade + short slide for swapping keyed children in place.
class BlueFadeSwitch extends StatelessWidget {
  const BlueFadeSwitch({
    super.key,
    required this.child,
    this.duration = BlueMotion.snap,
    this.offset = const Offset(0.045, 0),
  });

  final Widget child;
  final Duration duration;
  final Offset offset;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, duration),
      switchInCurve: BlueMotion.curve,
      switchOutCurve: BlueMotion.exitCurve,
      layoutBuilder: (current, previous) {
        return Stack(
          fit: StackFit.passthrough,
          children: [...previous, if (current != null) current],
        );
      },
      transitionBuilder: (child, animation) {
        return FadeTransition(
          opacity: animation,
          child: SlideTransition(
            position: Tween<Offset>(
              begin: offset,
              end: Offset.zero,
            ).animate(animation),
            child: child,
          ),
        );
      },
      child: child,
    );
  }
}

/// Keeps every child mounted and fades between them (bottom-nav tabs).
class BlueIndexedFade extends StatelessWidget {
  const BlueIndexedFade({
    super.key,
    required this.index,
    required this.children,
    this.duration = BlueMotion.snap,
  });

  final int index;
  final List<Widget> children;
  final Duration duration;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        for (var i = 0; i < children.length; i++)
          _BlueIndexedPane(
            active: i == index,
            duration: duration,
            child: children[i],
          ),
      ],
    );
  }
}

class _BlueIndexedPane extends StatelessWidget {
  const _BlueIndexedPane({
    required this.active,
    required this.duration,
    required this.child,
  });

  final bool active;
  final Duration duration;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      ignoring: !active,
      child: ExcludeSemantics(
        excluding: !active,
        child: AnimatedOpacity(
          opacity: active ? 1 : 0,
          duration: BlueMotion.of(context, duration),
          curve: BlueMotion.curve,
          child: TickerMode(enabled: active, child: child),
        ),
      ),
    );
  }
}

/// Shared push / pop layer used by the auth and signup stacks.
class BluePageLayer extends StatelessWidget {
  const BluePageLayer({
    super.key,
    required this.visible,
    required this.departLeft,
    required this.child,
  });

  final bool visible;
  final bool departLeft;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      ignoring: !visible,
      child: AnimatedSlide(
        duration: BlueMotion.page,
        curve: visible ? BlueMotion.curve : BlueMotion.exitCurve,
        offset: visible
            ? Offset.zero
            : (departLeft ? const Offset(-0.16, 0) : const Offset(1, 0)),
        child: AnimatedOpacity(
          duration: BlueMotion.page,
          curve: Curves.easeOut,
          opacity: visible ? 1 : 0,
          child: AnimatedScale(
            duration: BlueMotion.page,
            curve: BlueMotion.curve,
            scale: visible ? 1 : (departLeft ? 0.96 : 1),
            child: child,
          ),
        ),
      ),
    );
  }
}
