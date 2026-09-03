import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/property_models.dart';
import 'property_form_page.dart';
import 'widgets/properties_widgets.dart';

enum _PropertiesBody { loading, ready, error }

class MyPropertiesPage extends StatefulWidget {
  const MyPropertiesPage({super.key});

  @override
  State<MyPropertiesPage> createState() => _MyPropertiesPageState();
}

class _MyPropertiesPageState extends State<MyPropertiesPage> {
  _PropertiesBody _body = _PropertiesBody.loading;
  List<SavedProperty> _properties = const [];
  PropertyDraft? _addDraft;
  final _editDrafts = <String, PropertyDraft>{};
  String? _justSaved;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _PropertiesBody.loading);
    }
    try {
      final properties = await AppScope.of(context).properties.list();
      if (!mounted || seq != _seq) return;
      setState(() {
        _properties = properties;
        _body = _PropertiesBody.ready;
      });
    } on ApiException {
      if (!mounted || seq != _seq) return;
      setState(() => _body = silent ? _body : _PropertiesBody.error);
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() => _body = silent ? _body : _PropertiesBody.error);
    }
  }

  String get _countLabel {
    final n = _properties.length;
    if (n == 1) return '1 saved property';
    return '$n saved properties';
  }

  Future<void> _openAdd() async {
    final result = await Navigator.of(context).push<PropertyFormPop>(
      BluePageRoute(builder: (_) => PropertyFormPage(draft: _addDraft)),
    );
    if (!mounted) return;
    if (result == null) return;
    if (result.saved) {
      setState(() {
        _addDraft = null;
        _justSaved = result.savedUuid;
      });
      await _load(silent: true);
      return;
    }
    setState(() => _addDraft = result.draft);
  }

  Future<void> _openEdit(SavedProperty property) async {
    final result = await Navigator.of(context).push<PropertyFormPop>(
      BluePageRoute(
        builder: (_) => PropertyFormPage(
          property: property,
          draft: _editDrafts[property.uuid],
        ),
      ),
    );
    if (!mounted) return;
    if (result == null) return;
    if (result.saved) {
      setState(() {
        _editDrafts.remove(property.uuid);
        _justSaved = result.savedUuid ?? property.uuid;
      });
      await _load(silent: true);
      return;
    }
    if (result.draft != null) {
      setState(() => _editDrafts[property.uuid] = result.draft!);
    }
  }

  @override
  Widget build(BuildContext context) {
    final showAdd = _body == _PropertiesBody.ready && _properties.isNotEmpty;
    final subtitle = _body == _PropertiesBody.ready && _properties.isNotEmpty
        ? _countLabel
        : '';

    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
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
                  padding: const EdgeInsets.symmetric(
                    horizontal: BlueDimens.homeGutter,
                  ),
                  child: Row(
                    children: [
                      PropertiesBackButton(
                        onPressed: () {
                          final nav = Navigator.of(context);
                          if (nav.canPop()) nav.pop();
                        },
                      ),
                      const Spacer(),
                      if (showAdd) PropertiesAddAction(onPressed: _openAdd),
                    ],
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  BlueDimens.homeGutter,
                  2,
                  BlueDimens.homeGutter,
                  0,
                ),
                child: PropertiesTitle(
                  title: 'My properties',
                  subtitle: subtitle,
                ),
              ),
              Expanded(child: _bodyChild()),
            ],
          ),
        ),
      ),
    );
  }

  Widget _bodyChild() {
    switch (_body) {
      case _PropertiesBody.loading:
        return const PropertiesSkeleton();
      case _PropertiesBody.error:
        return PropertiesErrorState(onRetry: () => _load());
      case _PropertiesBody.ready:
        if (_properties.isEmpty) {
          return PropertiesEmptyState(onAdd: _openAdd);
        }
        return ListView.builder(
          physics: const BouncingScrollPhysics(
            parent: AlwaysScrollableScrollPhysics(),
          ),
          padding: const EdgeInsets.only(top: 18),
          itemCount: _properties.length + 1,
          itemBuilder: (context, index) {
            if (index == _properties.length) {
              return const DecoratedBox(
                decoration: BoxDecoration(
                  border: Border(top: BorderSide(color: BlueColors.navLine)),
                ),
                child: PropertiesFootNote(
                  text:
                      "Tap a property to edit its details. Changing a property never changes bookings or contracts you've already made.",
                ),
              );
            }
            final property = _properties[index];
            return PropertyRow(
              property: property,
              fadeIn: property.uuid == _justSaved,
              onPressed: () => _openEdit(property),
            );
          },
        );
    }
  }
}
