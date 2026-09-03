import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class HomeHeroArt extends StatelessWidget {
  const HomeHeroArt({super.key});

  @override
  Widget build(BuildContext context) {
    return ShaderMask(
      shaderCallback: (rect) {
        return const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.black, Colors.black, Colors.transparent],
          stops: [0.0, 0.85, 1.0],
        ).createShader(rect);
      },
      blendMode: BlendMode.dstIn,
      child: Image.asset(
        'assets/brand/home-villa-hero.png',
        fit: BoxFit.cover,
        alignment: Alignment.bottomRight,
        filterQuality: FilterQuality.high,
        width: double.infinity,
        height: double.infinity,
      ),
    );
  }
}
