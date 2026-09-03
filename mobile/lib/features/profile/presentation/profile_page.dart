import 'dart:async';

import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/data/reference_data.dart';
import '../../auth/presentation/widgets/blue_field_label.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/blue_outlined_field.dart';
import '../../auth/presentation/widgets/blue_sheet.dart';
import '../../auth/presentation/widgets/error_hint.dart';
import '../data/customer_profile.dart';
import 'change_phone_page.dart';
import 'widgets/profile_widgets.dart';

enum _ProfileBody { loading, ready, error }

class ProfilePage extends StatefulWidget {
  const ProfilePage({super.key});

  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage> with WidgetsBindingObserver {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _nameFocus = FocusNode();
  final _emailFocus = FocusNode();
  final _scroll = ScrollController();

  _ProfileBody _body = _ProfileBody.loading;
  String _savedName = '';
  String _savedEmail = '';
  String _phone = '';
  List<RefItem> _catalogue = const [];
  List<RefItem> _savedPicks = const [];
  final List<RefItem> _picks = [];
  bool _nameErr = false;
  bool _emailErr = false;
  bool _saving = false;
  bool _saveFailed = false;
  bool _toast = false;
  String _toastLabel = 'Profile updated';
  bool _sheetOpen = false;
  bool _sheetVisible = false;
  bool _dirtyHaptic = false;
  int _seq = 0;
  int _toastGen = 0;
  Timer? _toastTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _toastTimer?.cancel();
    _name.dispose();
    _email.dispose();
    _nameFocus.dispose();
    _emailFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _body == _ProfileBody.ready) {
      _load(silent: true);
    }
  }

  void _goBack() {
    if (_sheetVisible) {
      _closeSheet();
      return;
    }
    final nav = Navigator.of(context);
    if (nav.canPop()) {
      nav.pop();
      return;
    }
    // If the route stack is stuck, at least restore shell chrome.
    AppScope.of(context).shell.hideNav.value = false;
  }

  void _markEdited() {
    final nowDirty = _isDirty;
    if (nowDirty && !_dirtyHaptic) BlueMotion.tap();
    _dirtyHaptic = nowDirty;
  }

  bool _nameOk(String value) =>
      value.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).length >= 2;

  bool _emailOk(String value) => RegExp(
    r'^[^\s@]+@[^\s@]+\.[a-z]{2,}$',
    caseSensitive: false,
  ).hasMatch(value.trim());

  bool get _isDirty {
    if (_name.text.trim() != _savedName) return true;
    if (_email.text.trim() != _savedEmail) return true;
    return !_samePicks(_picks, _savedPicks);
  }

  void _flashToast([String label = 'Profile updated']) {
    setState(() {
      _toastLabel = label;
      _toast = true;
    });
    BlueMotion.tap();
    final gen = ++_toastGen;
    _toastTimer?.cancel();
    _toastTimer = Timer(profileToastHold, () {
      if (!mounted || gen != _toastGen) return;
      setState(() => _toast = false);
    });
  }

  Future<void> _openChangePhone() async {
    final changed = await Navigator.of(
      context,
    ).push<bool>(BluePageRoute(builder: (_) => const ChangePhonePage()));
    if (!mounted || changed != true) return;
    await _load(silent: true);
    if (!mounted) return;
    _flashToast('Phone number updated');
  }

  bool _samePicks(List<RefItem> a, List<RefItem> b) {
    if (a.length != b.length) return false;
    final left = a.map((item) => item.id).toList()..sort();
    final right = b.map((item) => item.id).toList()..sort();
    for (var i = 0; i < left.length; i++) {
      if (left[i] != right[i]) return false;
    }
    return true;
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _ProfileBody.loading);
    }
    try {
      final scope = AppScope.of(context);
      final profileFuture = scope.profile.get(force: true);
      final refFuture = scope.referenceData.load();
      final profile = await profileFuture;
      final refs = await refFuture;
      if (!mounted || seq != _seq) return;
      _applyLoaded(
        profile,
        refs.serviceCategories,
        keepEdits: silent && _isDirty,
      );
    } catch (_) {
      if (!mounted || seq != _seq) return;
      if (silent) return;
      setState(() => _body = _ProfileBody.error);
    }
  }

  void _applyLoaded(
    CustomerProfile profile,
    List<RefItem> catalogue, {
    required bool keepEdits,
  }) {
    final interests = [
      for (final item in profile.serviceInterests)
        RefItem(id: item.id, name: item.name, code: item.code),
    ];
    final merged = _mergeCatalogue(catalogue, interests);
    setState(() {
      _catalogue = merged;
      _savedName = profile.fullName.trim();
      _savedEmail = profile.email.trim();
      _phone = profile.phoneNumber;
      _savedPicks = List<RefItem>.from(interests);
      _body = _ProfileBody.ready;
      if (!keepEdits) {
        _name.text = _savedName;
        _email.text = _savedEmail;
        _picks
          ..clear()
          ..addAll(interests);
        _nameErr = false;
        _emailErr = false;
        _saveFailed = false;
        _dirtyHaptic = false;
      }
    });
  }

  List<RefItem> _mergeCatalogue(List<RefItem> catalogue, List<RefItem> picks) {
    final byId = <int, RefItem>{for (final item in catalogue) item.id: item};
    for (final pick in picks) {
      byId.putIfAbsent(pick.id, () => pick);
    }
    if (catalogue.isEmpty) return byId.values.toList();
    final ordered = <RefItem>[];
    for (final item in catalogue) {
      ordered.add(byId[item.id]!);
    }
    for (final pick in picks) {
      if (ordered.every((item) => item.id != pick.id)) ordered.add(pick);
    }
    return ordered;
  }

  List<RefItem> get _shownInterests {
    final rest = _catalogue.where(
      (item) => !_picks.any((pick) => pick.id == item.id),
    );
    return [..._picks, ...rest].take(4).toList();
  }

  void _toggleInterest(RefItem item) {
    if (_saving) return;
    setState(() {
      final index = _picks.indexWhere((pick) => pick.id == item.id);
      if (index >= 0) {
        _picks.removeAt(index);
      } else {
        _picks.add(item);
      }
      _saveFailed = false;
    });
    _markEdited();
  }

  void _openSheet() {
    if (_saving) return;
    setState(() {
      _sheetVisible = true;
      _sheetOpen = false;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_sheetVisible) return;
      setState(() => _sheetOpen = true);
    });
  }

  void _closeSheet() {
    if (!_sheetOpen && !_sheetVisible) return;
    setState(() => _sheetOpen = false);
    Future<void>.delayed(BlueMotion.sheetOut, () {
      if (!mounted || _sheetOpen) return;
      setState(() => _sheetVisible = false);
    });
  }

  Future<void> _save() async {
    if (_saving) return;
    FocusScope.of(context).unfocus();
    final nameErr = !_nameOk(_name.text);
    final emailErr = !_emailOk(_email.text);
    if (nameErr || emailErr) {
      BlueMotion.warn();
      setState(() {
        _nameErr = nameErr;
        _emailErr = emailErr;
        _saveFailed = false;
      });
      return;
    }
    if (!_isDirty) return;
    setState(() {
      _saving = true;
      _saveFailed = false;
      _nameErr = false;
      _emailErr = false;
    });
    try {
      final name = _name.text.trim();
      final email = _email.text.trim();
      final updated = await AppScope.of(context).profile.update(
        fullName: name != _savedName ? name : null,
        email: email != _savedEmail ? email : null,
        serviceInterests: _samePicks(_picks, _savedPicks)
            ? null
            : _picks.map((item) => item.id).toList(),
      );
      if (!mounted) return;
      await AppScope.of(
        context,
      ).auth.syncIdentity(fullName: updated.fullName, email: updated.email);
      if (!mounted) return;
      setState(() {
        _saving = false;
        _savedName = updated.fullName.trim();
        _savedEmail = updated.email.trim();
        _phone = updated.phoneNumber;
        _savedPicks = [
          for (final item in updated.serviceInterests)
            RefItem(id: item.id, name: item.name, code: item.code),
        ];
        _picks
          ..clear()
          ..addAll(_savedPicks);
        _dirtyHaptic = false;
      });
      _flashToast();
    } on ApiException catch (error) {
      if (!mounted) return;
      BlueMotion.warn();
      final nameMsg = error.field('full_name');
      final emailMsg = error.field('email');
      setState(() {
        _saving = false;
        _nameErr = nameMsg != null;
        _emailErr = emailMsg != null;
        _saveFailed = nameMsg == null && emailMsg == null;
      });
    } catch (_) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _saving = false;
        _saveFailed = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final keyboardOpen = MediaQuery.viewInsetsOf(context).bottom > 0;
    return PopScope(
      canPop: !_sheetVisible,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_sheetVisible) _closeSheet();
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: Stack(
          children: [
            SafeArea(
              bottom: false,
              child: BlueEnter(
                duration: BlueMotion.rise,
                offset: const Offset(0, 0.018),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      height: 52,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 24),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: ProfileBackButton(onPressed: _goBack),
                        ),
                      ),
                    ),
                    const Padding(
                      padding: EdgeInsets.fromLTRB(24, 2, 24, 0),
                      child: ProfileTitle(),
                    ),
                    Expanded(child: _bodyChild(keyboardOpen)),
                    if (!keyboardOpen &&
                        _body == _ProfileBody.ready &&
                        !_sheetVisible)
                      _saveArea(),
                  ],
                ),
              ),
            ),
            if (_sheetVisible) _buildSheet(),
            if (_body == _ProfileBody.ready)
              Align(
                alignment: Alignment.bottomCenter,
                child: ProfileToast(visible: _toast, label: _toastLabel),
              ),
          ],
        ),
      ),
    );
  }

  Widget _bodyChild(bool keyboardOpen) {
    switch (_body) {
      case _ProfileBody.loading:
        return const ProfileSkeleton();
      case _ProfileBody.error:
        return ProfileLoadError(onRetry: () => _load());
      case _ProfileBody.ready:
        return _form(keyboardOpen);
    }
  }

  Widget _form(bool keyboardOpen) {
    final hidden = _catalogue.length - _shownInterests.length;
    return ListView(
      controller: _scroll,
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      physics: const BouncingScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: EdgeInsets.fromLTRB(24, 12, 24, keyboardOpen ? 24 : 8),
      children: [
        const BlueFieldLabel('Full name'),
        const SizedBox(height: 8),
        Focus(
          onFocusChange: (has) {
            if (!has) {
              setState(() {
                _nameErr = _name.text.isNotEmpty && !_nameOk(_name.text);
              });
            }
          },
          child: BlueOutlinedField(
            key: const Key('profile-name'),
            controller: _name,
            focusNode: _nameFocus,
            hint: 'Aisha Al Mansoori',
            error: _nameErr,
            enabled: !_saving,
            textCapitalization: TextCapitalization.words,
            textInputAction: TextInputAction.next,
            autofillHints: const [AutofillHints.name],
            onChanged: (_) => setState(() {
              _nameErr = false;
              _saveFailed = false;
              _markEdited();
            }),
          ),
        ),
        AnimatedSize(
          duration: BlueMotion.snap,
          curve: BlueMotion.curve,
          alignment: Alignment.topCenter,
          child: _nameErr
              ? const Padding(
                  padding: EdgeInsets.only(top: 8),
                  child: BlueErrorHint(
                    message: 'Enter your full name as it appears on your ID.',
                  ),
                )
              : const SizedBox.shrink(),
        ),
        const SizedBox(height: 14),
        const BlueFieldLabel('Email address'),
        const SizedBox(height: 8),
        Focus(
          onFocusChange: (has) {
            if (!has) {
              setState(() {
                _emailErr = _email.text.isNotEmpty && !_emailOk(_email.text);
              });
            }
          },
          child: BlueOutlinedField(
            key: const Key('profile-email'),
            controller: _email,
            focusNode: _emailFocus,
            hint: 'you@example.com',
            error: _emailErr,
            enabled: !_saving,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.done,
            autocorrect: false,
            enableSuggestions: false,
            autofillHints: const [AutofillHints.email],
            onChanged: (_) => setState(() {
              _emailErr = false;
              _saveFailed = false;
              _markEdited();
            }),
            onSubmitted: (_) => _save(),
          ),
        ),
        AnimatedSize(
          duration: BlueMotion.snap,
          curve: BlueMotion.curve,
          alignment: Alignment.topCenter,
          child: _emailErr
              ? const Padding(
                  padding: EdgeInsets.only(top: 8),
                  child: BlueErrorHint(
                    message: 'Check this email address — it looks incomplete.',
                  ),
                )
              : const SizedBox.shrink(),
        ),
        const SizedBox(height: 8),
        const ProfileHelper(
          'Where we send booking and contract confirmations.',
        ),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 14),
          child: ProfileHairline(),
        ),
        const BlueFieldLabel('Phone number'),
        const SizedBox(height: 8),
        ProfilePhoneRow(
          phone: formatProfilePhone(_phone),
          onChange: _openChangePhone,
        ),
        const SizedBox(height: 8),
        const ProfileHelper(
          'This is how you sign in, so changing it needs a code sent to the new number.',
        ),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 14),
          child: ProfileHairline(),
        ),
        const Row(
          children: [
            Expanded(
              child: Text(
                'What can we help with?',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 13 * 0.005,
                  color: BlueColors.muted,
                ),
              ),
            ),
            ProfileOptionalBadge(),
          ],
        ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final item in _shownInterests)
              ProfileInterestChip(
                label: item.name,
                selected: _picks.any((pick) => pick.id == item.id),
                onPressed: () => _toggleInterest(item),
              ),
            if (hidden > 0)
              ProfileMoreChip(label: '+$hidden more', onPressed: _openSheet),
          ],
        ),
        const SizedBox(height: 8),
        const ProfileHelper(
          'Helps us put the services you use first. It never limits what you can book.',
        ),
      ],
    );
  }

  Widget _saveArea() {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_saveFailed) const ProfileFailStrip(),
        Padding(
          padding: EdgeInsets.fromLTRB(
            24,
            _saveFailed ? 12 : 8,
            24,
            bottom < 18 ? 18 : bottom,
          ),
          child: Column(
            children: [
              ProfileSaveButton(
                enabled: _isDirty && !_saving,
                busy: _saving,
                onPressed: _save,
              ),
              AnimatedSize(
                duration: BlueMotion.of(context, profileSaveWake),
                curve: BlueMotion.curve,
                child: !_isDirty && !_saving
                    ? const Padding(
                        padding: EdgeInsets.only(top: 8),
                        child: Text(
                          'Change something to save.',
                          style: TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 12.5,
                            height: 1.4,
                            fontWeight: FontWeight.w400,
                            color: BlueColors.muted,
                          ),
                        ),
                      )
                    : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildSheet() {
    return BlueHostedSheet(
      open: _sheetOpen,
      onDismiss: _closeSheet,
      child: BlueSheetPanel(
        title: 'What can we help with?',
        onClose: _closeSheet,
        header: const Padding(
          padding: EdgeInsets.fromLTRB(20, 2, 20, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Text(
              'Pick as many as you like, or none at all.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.4,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
        ),
        footer: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
          child: DecoratedBox(
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.sheetHairline)),
            ),
            child: Padding(
              padding: const EdgeInsets.only(top: 8),
              child: BluePressable(
                onPressed: _closeSheet,
                child: AnimatedContainer(
                  duration: BlueMotion.snap,
                  width: double.infinity,
                  height: 54,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: BlueColors.ink,
                    borderRadius: BorderRadius.circular(18),
                  ),
                  child: AnimatedSwitcher(
                    duration: BlueMotion.tick,
                    child: Text(
                      _picks.isEmpty
                          ? 'Done'
                          : 'Done · ${_picks.length} selected',
                      key: ValueKey(_picks.length),
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.white,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 8),
          shrinkWrap: true,
          children: [
            for (var i = 0; i < _catalogue.length; i++)
              BlueSheetRow(
                index: i,
                label: _catalogue[i].name,
                selected: _picks.any((pick) => pick.id == _catalogue[i].id),
                onPressed: () => _toggleInterest(_catalogue[i]),
              ),
          ],
        ),
      ),
    );
  }
}
