import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import 'blue_motion.dart';

class BlueTextLink extends StatefulWidget {
  const BlueTextLink({
    super.key,
    required this.label,
    required this.onPressed,
    this.fontSize = 14,
  });

  final String label;
  final VoidCallback onPressed;
  final double fontSize;

  @override
  State<BlueTextLink> createState() => _BlueTextLinkState();
}

class _BlueTextLinkState extends State<BlueTextLink> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedScale(
        scale: _down ? 0.96 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: BlueMotion.press,
          constraints: const BoxConstraints(minHeight: 44),
          padding: const EdgeInsets.symmetric(horizontal: 8),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            widget.label,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: widget.fontSize,
              fontWeight: FontWeight.w700,
              letterSpacing: widget.fontSize * 0.005,
              color: BlueColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}
