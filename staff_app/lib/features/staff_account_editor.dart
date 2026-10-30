import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class StaffAccountEditor extends StatefulWidget {
  const StaffAccountEditor({
    super.key,
    required this.roles,
    required this.onSave,
    this.user,
    this.currentUserId,
  });

  final List roles;
  final Map? user;
  final int? currentUserId;
  final Future<void> Function(Map<String, dynamic>) onSave;

  @override
  State<StaffAccountEditor> createState() => _StaffAccountEditorState();
}

class _StaffAccountEditorState extends State<StaffAccountEditor> {
  final form = GlobalKey<FormState>();
  late final TextEditingController name, username, email, mobile;
  final password = TextEditingController(), pin = TextEditingController();
  late int roleId;
  bool showPassword = false, showPin = false, clearPin = false, busy = false;
  String? error;
  bool get editing => widget.user != null;

  @override
  void initState() {
    super.initState();
    name = TextEditingController(text: widget.user?['name']?.toString());
    username = TextEditingController(
      text: widget.user?['username']?.toString(),
    );
    email = TextEditingController(text: widget.user?['email']?.toString());
    mobile = TextEditingController(text: widget.user?['mobile']?.toString());
    roleId = ((widget.user?['role_id'] ?? widget.roles.first['id']) as num)
        .toInt();
  }

  @override
  void dispose() {
    for (final controller in [name, username, email, mobile, password, pin]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    if (busy || !form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.onSave({
        'role_id': roleId,
        'name': name.text.trim(),
        'username': username.text.trim(),
        'email': email.text.trim(),
        'mobile': mobile.text.trim(),
        if (!editing || password.text.isNotEmpty) 'password': password.text,
        if (pin.text.isNotEmpty && !clearPin) 'pin': pin.text,
        if (editing) 'clear_pin': clearPin,
      });
      if (mounted) {
        setState(() => busy = false);
        Navigator.pop(context, true);
      }
    } catch (exception) {
      if (mounted) {
        setState(() {
          busy = false;
          error = exception.toString();
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !busy,
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        18,
        20,
        MediaQuery.viewInsetsOf(context).bottom + 20,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 620),
          child: SingleChildScrollView(
            child: Form(
              key: form,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          editing
                              ? 'Edit staff account'
                              : 'Create staff account',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                      ),
                      IconButton(
                        tooltip: 'Close staff editor',
                        onPressed: busy ? null : () => Navigator.pop(context),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  if (editing)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 10),
                      child: Text(
                        'Leave password and PIN blank to keep them unchanged.',
                      ),
                    ),
                  DropdownButtonFormField<int>(
                    initialValue: roleId,
                    decoration: const InputDecoration(labelText: 'Role'),
                    items: widget.roles
                        .map(
                          (role) => DropdownMenuItem<int>(
                            value: (role['id'] as num).toInt(),
                            child: Text('${role['display_name']}'),
                          ),
                        )
                        .toList(),
                    onChanged:
                        busy ||
                            (editing &&
                                widget.user!['id'] == widget.currentUserId)
                        ? null
                        : (value) => setState(() => roleId = value!),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: name,
                    enabled: !busy,
                    maxLength: 255,
                    decoration: const InputDecoration(labelText: 'Full name'),
                    validator: (value) =>
                        value!.trim().isEmpty ? 'Enter a full name.' : null,
                  ),
                  TextFormField(
                    controller: username,
                    enabled: !busy,
                    maxLength: 100,
                    decoration: const InputDecoration(labelText: 'Username'),
                    validator: (value) =>
                        RegExp(
                          r'^[A-Za-z0-9._-]{3,100}$',
                        ).hasMatch(value!.trim())
                        ? null
                        : 'Use 3–100 letters, numbers, dots, dashes or underscores.',
                  ),
                  TextFormField(
                    controller: email,
                    enabled: !busy,
                    maxLength: 255,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(
                      labelText: 'Email (optional)',
                    ),
                    validator: (value) =>
                        value!.trim().isEmpty ||
                            RegExp(
                              r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                            ).hasMatch(value.trim())
                        ? null
                        : 'Enter a valid email address.',
                  ),
                  TextFormField(
                    controller: mobile,
                    enabled: !busy,
                    maxLength: 30,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Mobile (optional)',
                    ),
                  ),
                  TextFormField(
                    controller: password,
                    enabled: !busy,
                    obscureText: !showPassword,
                    inputFormatters: [LengthLimitingTextInputFormatter(255)],
                    decoration: InputDecoration(
                      labelText: editing ? 'New password' : 'Password',
                      helperText: 'At least 8 characters',
                      suffixIcon: IconButton(
                        tooltip: showPassword
                            ? 'Hide password'
                            : 'Show password',
                        onPressed: busy
                            ? null
                            : () =>
                                  setState(() => showPassword = !showPassword),
                        icon: Icon(
                          showPassword
                              ? Icons.visibility_off_outlined
                              : Icons.visibility_outlined,
                        ),
                      ),
                    ),
                    validator: (value) =>
                        (editing && value!.isEmpty) ||
                            (value != null && value.length >= 8)
                        ? null
                        : 'Use at least 8 characters.',
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: pin,
                    enabled: !busy && !clearPin,
                    obscureText: !showPin,
                    keyboardType: TextInputType.number,
                    inputFormatters: [
                      FilteringTextInputFormatter.digitsOnly,
                      LengthLimitingTextInputFormatter(12),
                    ],
                    decoration: InputDecoration(
                      labelText: 'PIN (optional)',
                      helperText: '4 to 12 digits',
                      suffixIcon: IconButton(
                        tooltip: showPin ? 'Hide PIN' : 'Show PIN',
                        onPressed: busy || clearPin
                            ? null
                            : () => setState(() => showPin = !showPin),
                        icon: Icon(
                          showPin
                              ? Icons.visibility_off_outlined
                              : Icons.visibility_outlined,
                        ),
                      ),
                    ),
                    validator: (value) =>
                        clearPin ||
                            value!.isEmpty ||
                            RegExp(r'^\d{4,12}$').hasMatch(value)
                        ? null
                        : 'Use 4 to 12 digits.',
                  ),
                  if (editing)
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      title: const Text('Remove saved PIN'),
                      value: clearPin,
                      onChanged: busy
                          ? null
                          : (value) => setState(() => clearPin = value!),
                    ),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      child: Text(
                        error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: busy ? null : () => Navigator.pop(context),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton(
                          onPressed: busy ? null : save,
                          child: Text(
                            busy
                                ? 'Saving…'
                                : editing
                                ? 'Save changes'
                                : 'Create account',
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
