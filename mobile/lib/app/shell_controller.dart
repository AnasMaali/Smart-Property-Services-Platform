import 'package:flutter/foundation.dart';

import '../features/shell/presentation/widgets/blue_bottom_nav.dart';

class ShellController {
  final tab = ValueNotifier<BlueTab>(BlueTab.home);
  final hideNav = ValueNotifier<bool>(false);
  final cartCount = ValueNotifier<int>(0);

  void openTab(BlueTab value) {
    hideNav.value = false;
    tab.value = value;
  }

  void dispose() {
    tab.dispose();
    hideNav.dispose();
    cartCount.dispose();
  }
}
