import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'auth_session.dart';

abstract class SessionStore {
  AuthSession? get current;

  Future<void> load();

  Future<void> save(AuthSession session);

  Future<void> clear();
}

class SecureSessionStore implements SessionStore {
  SecureSessionStore({FlutterSecureStorage? storage})
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
          );

  static const _key = 'blue.auth.session.v1';

  final FlutterSecureStorage _storage;
  AuthSession? _session;

  @override
  AuthSession? get current => _session;

  @override
  Future<void> load() async {
    final raw = await _storage.read(key: _key);
    if (raw == null || raw.isEmpty) {
      _session = null;
      return;
    }
    try {
      _session = AuthSession.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      _session = null;
      await _storage.delete(key: _key);
    }
  }

  @override
  Future<void> save(AuthSession session) async {
    _session = session;
    await _storage.write(key: _key, value: jsonEncode(session.toJson()));
  }

  @override
  Future<void> clear() async {
    _session = null;
    await _storage.delete(key: _key);
  }
}

class MemorySessionStore implements SessionStore {
  AuthSession? _session;

  @override
  AuthSession? get current => _session;

  @override
  Future<void> load() async {}

  @override
  Future<void> save(AuthSession session) async {
    _session = session;
  }

  @override
  Future<void> clear() async {
    _session = null;
  }
}
