import 'dart:async';
import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:intl/intl.dart';

class BdeCreateTaskScreen extends StatefulWidget {
  final VoidCallback? onTaskCreated;

  const BdeCreateTaskScreen({super.key, this.onTaskCreated});

  @override
  State<BdeCreateTaskScreen> createState() => _BdeCreateTaskScreenState();
}

class _BdeCreateTaskScreenState extends State<BdeCreateTaskScreen> {
  final _formKey = GlobalKey<FormState>();
  final ApiService _apiService = ApiService();

  final _taskKeyController = TextEditingController();
  final _taskNameController = TextEditingController();
  final _clientNameController = TextEditingController();
  final _wordCountController = TextEditingController(text: '1000');
  final _universityController = TextEditingController();
  final _countryController = TextEditingController();
  final _subjectController = TextEditingController();
  final _instructionsController = TextEditingController();
  final _driveLinkController = TextEditingController();

  DateTime _selectedDeadline = DateTime.now().add(const Duration(days: 3));
  TimeOfDay _selectedTime = const TimeOfDay(hour: 23, minute: 0);
  bool _isUrgent = false;

  bool _isCheckingKey = false;
  bool? _isKeyAvailable;
  String _keyCheckMessage = '';
  Timer? _keyDebounce;

  bool _isSubmitting = false;

  @override
  void dispose() {
    _taskKeyController.dispose();
    _taskNameController.dispose();
    _clientNameController.dispose();
    _wordCountController.dispose();
    _universityController.dispose();
    _countryController.dispose();
    _subjectController.dispose();
    _instructionsController.dispose();
    _driveLinkController.dispose();
    _keyDebounce?.cancel();
    super.dispose();
  }

  void _onTaskKeyChanged(String value) {
    _keyDebounce?.cancel();
    final key = value.trim();
    if (key.isEmpty) {
      setState(() {
        _isCheckingKey = false;
        _isKeyAvailable = null;
        _keyCheckMessage = '';
      });
      return;
    }

    setState(() {
      _isCheckingKey = true;
      _isKeyAvailable = null;
    });

    _keyDebounce = Timer(const Duration(milliseconds: 500), () async {
      final res = await _apiService.checkTaskKey(key);
      if (!mounted) return;
      setState(() {
        _isCheckingKey = false;
        if (res['exists'] == true) {
          _isKeyAvailable = false;
          _keyCheckMessage = 'Key already exists: "${res['task_name'] ?? ''}"';
        } else {
          _isKeyAvailable = true;
          _keyCheckMessage = 'Task key is available';
        }
      });
    });
  }

  Future<void> _pickDeadlineDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDeadline,
      firstDate: DateTime.now().subtract(const Duration(days: 1)),
      lastDate: DateTime.now().add(const Duration(days: 365)),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppColors.primary,
              onPrimary: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() => _selectedDeadline = picked);
    }
  }

  Future<void> _pickDeadlineTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _selectedTime,
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppColors.primary,
              onPrimary: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() => _selectedTime = picked);
    }
  }

  Future<void> _submitTask() async {
    if (!_formKey.currentState!.validate()) return;

    if (_isKeyAvailable == false) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter a unique Task Key'),
          backgroundColor: AppColors.error,
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final finalDeadline = DateTime(
      _selectedDeadline.year,
      _selectedDeadline.month,
      _selectedDeadline.day,
      _selectedTime.hour,
      _selectedTime.minute,
    );
    final deadlineStr = DateFormat('yyyy-MM-dd HH:mm:ss').format(finalDeadline);

    final payload = {
      'task_key': _taskKeyController.text.trim(),
      'task_name': _taskNameController.text.trim(),
      'client_name': _clientNameController.text.trim(),
      'word_count': int.tryParse(_wordCountController.text.trim()) ?? 0,
      'deadline': deadlineStr,
      'university': _universityController.text.trim(),
      'country': _countryController.text.trim(),
      'subject': _subjectController.text.trim(),
      'instructions': _instructionsController.text.trim(),
      'urgent': _isUrgent ? 'yes' : 'no',
      'drive_link': _driveLinkController.text.trim(),
    };

    final res = await _apiService.createBdeTask(payload);

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Task "${_taskKeyController.text}" created successfully!'),
          backgroundColor: AppColors.success,
        ),
      );

      widget.onTaskCreated?.call();

      if (Navigator.canPop(context)) {
        Navigator.pop(context, true);
      } else {
        // Reset form
        _formKey.currentState!.reset();
        _taskKeyController.clear();
        _taskNameController.clear();
        _clientNameController.clear();
        _instructionsController.clear();
        _driveLinkController.clear();
        setState(() {
          _isKeyAvailable = null;
          _keyCheckMessage = '';
          _isUrgent = false;
        });
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Failed to create task'),
          backgroundColor: AppColors.error,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final deadlineFormatted = DateFormat('dd MMM yyyy').format(_selectedDeadline);
    final timeFormatted = _selectedTime.format(context);

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text(
          'Create New Task',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
        ),
        backgroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        iconTheme: const IconThemeData(color: AppColors.textPrimary),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(color: AppColors.border, height: 1),
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ─── Section 1: Task Identification ─────────────────
              _buildSectionCard(
                title: 'TASK IDENTIFICATION',
                icon: Icons.tag_rounded,
                children: [
                  // Task Key
                  const Text('Task Key *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _taskKeyController,
                    onChanged: _onTaskKeyChanged,
                    textCapitalization: TextCapitalization.characters,
                    decoration: InputDecoration(
                      hintText: 'e.g. DI/NOV26/PEARL/072',
                      prefixIcon: const Icon(Icons.vpn_key_rounded, size: 18, color: AppColors.textMuted),
                      suffixIcon: _isCheckingKey
                          ? const Padding(
                              padding: EdgeInsets.all(12),
                              child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary)),
                            )
                          : _isKeyAvailable == true
                              ? const Icon(Icons.check_circle_rounded, color: AppColors.success, size: 20)
                              : _isKeyAvailable == false
                                  ? const Icon(Icons.cancel_rounded, color: AppColors.error, size: 20)
                                  : null,
                    ),
                    validator: (v) {
                      if (v == null || v.trim().isEmpty) return 'Task Key is required';
                      if (_isKeyAvailable == false) return 'Task Key already exists';
                      return null;
                    },
                  ),
                  if (_keyCheckMessage.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      _keyCheckMessage,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: _isKeyAvailable == true ? AppColors.success : AppColors.error,
                      ),
                    ),
                  ],
                  const SizedBox(height: 14),

                  // Project / Task Name
                  const Text('Task / Project Title *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _taskNameController,
                    decoration: const InputDecoration(
                      hintText: 'e.g. Strategic Management Case Study',
                      prefixIcon: Icon(Icons.title_rounded, size: 18, color: AppColors.textMuted),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Task title is required' : null,
                  ),
                  const SizedBox(height: 14),

                  // Client Name
                  const Text('Client Name *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _clientNameController,
                    decoration: const InputDecoration(
                      hintText: 'e.g. Navv / Dr. Miller',
                      prefixIcon: Icon(Icons.person_outline_rounded, size: 18, color: AppColors.textMuted),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Client name is required' : null,
                  ),
                ],
              ),
              const SizedBox(height: 14),

              // ─── Section 2: Scope & Deadlines ───────────────────
              _buildSectionCard(
                title: 'SCOPE & TIMELINE',
                icon: Icons.timer_outlined,
                children: [
                  Row(
                    children: [
                      // Word Count
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Word Count *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                            const SizedBox(height: 6),
                            TextFormField(
                              controller: _wordCountController,
                              keyboardType: TextInputType.number,
                              decoration: const InputDecoration(
                                hintText: '1500',
                                prefixIcon: Icon(Icons.edit_note_rounded, size: 18, color: AppColors.textMuted),
                              ),
                              validator: (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      // Urgent Switch
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Priority Level', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                            const SizedBox(height: 6),
                            Container(
                              height: 48,
                              padding: const EdgeInsets.symmetric(horizontal: 10),
                              decoration: BoxDecoration(
                                color: _isUrgent ? const Color(0xFFFDE8EC) : AppColors.surface,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: _isUrgent ? AppColors.error : AppColors.border),
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Row(
                                    children: [
                                      Icon(
                                        Icons.priority_high_rounded,
                                        size: 16,
                                        color: _isUrgent ? AppColors.error : AppColors.textMuted,
                                      ),
                                      const SizedBox(width: 4),
                                      Text(
                                        _isUrgent ? 'URGENT' : 'Normal',
                                        style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.bold,
                                          color: _isUrgent ? AppColors.error : AppColors.textSecondary,
                                        ),
                                      ),
                                    ],
                                  ),
                                  Switch(
                                    value: _isUrgent,
                                    onChanged: (val) => setState(() => _isUrgent = val),
                                    activeColor: AppColors.error,
                                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  // Deadline Pickers
                  const Text('Deadline Date & Time *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      // Date Picker
                      Expanded(
                        flex: 3,
                        child: InkWell(
                          onTap: _pickDeadlineDate,
                          borderRadius: BorderRadius.circular(8),
                          child: Container(
                            height: 48,
                            padding: const EdgeInsets.symmetric(horizontal: 12),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: AppColors.border),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.calendar_today_rounded, size: 16, color: AppColors.textMuted),
                                const SizedBox(width: 8),
                                Text(
                                  deadlineFormatted,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      // Time Picker
                      Expanded(
                        flex: 2,
                        child: InkWell(
                          onTap: _pickDeadlineTime,
                          borderRadius: BorderRadius.circular(8),
                          child: Container(
                            height: 48,
                            padding: const EdgeInsets.symmetric(horizontal: 12),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: AppColors.border),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.access_time_rounded, size: 16, color: AppColors.textMuted),
                                const SizedBox(width: 8),
                                Text(
                                  timeFormatted,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 14),

              // ─── Section 3: Academic Details ────────────────────
              _buildSectionCard(
                title: 'ACADEMIC & SUBJECT INFO',
                icon: Icons.school_outlined,
                children: [
                  Row(
                    children: [
                      // University
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('University', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                            const SizedBox(height: 6),
                            TextFormField(
                              controller: _universityController,
                              decoration: const InputDecoration(hintText: 'e.g. Oxford / N/A'),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      // Country
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Country', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                            const SizedBox(height: 6),
                            TextFormField(
                              controller: _countryController,
                              decoration: const InputDecoration(hintText: 'e.g. UK / Canada'),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  // Subject / Software
                  const Text('Subject / Software', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _subjectController,
                    decoration: const InputDecoration(
                      hintText: 'e.g. Python / Finance / SPSS',
                      prefixIcon: Icon(Icons.menu_book_rounded, size: 18, color: AppColors.textMuted),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),

              // ─── Section 4: Instructions & Drive ────────────────
              _buildSectionCard(
                title: 'BRIEF & ATTACHMENTS',
                icon: Icons.description_outlined,
                children: [
                  // Drive link
                  const Text('Google Drive Folder Link', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _driveLinkController,
                    keyboardType: TextInputType.url,
                    decoration: const InputDecoration(
                      hintText: 'https://drive.google.com/drive/folders/...',
                      prefixIcon: Icon(Icons.add_to_drive_rounded, size: 18, color: Color(0xFF3B6CF6)),
                    ),
                  ),
                  const SizedBox(height: 14),

                  // Instructions
                  const Text('Client Instructions / Task Brief *', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                  const SizedBox(height: 6),
                  TextFormField(
                    controller: _instructionsController,
                    maxLines: 5,
                    decoration: const InputDecoration(
                      hintText: 'Paste complete project instructions, structure, references, and special guidelines here...',
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Instructions are required' : null,
                  ),
                ],
              ),
              const SizedBox(height: 20),

              // ─── Submit Button ──────────────────────────────────
              SizedBox(
                width: double.infinity,
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: _isSubmitting ? null : _submitTask,
                  icon: _isSubmitting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Icon(Icons.check_circle_rounded, size: 20, color: Colors.white),
                  label: Text(
                    _isSubmitting ? 'Creating Task in System...' : 'Create & Dispatch Task',
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.white),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    elevation: 0,
                  ),
                ),
              ),
              const SizedBox(height: 30),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildSectionCard({
    required String title,
    required IconData icon,
    required List<Widget> children,
  }) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(color: Color(0x04000000), blurRadius: 4, offset: Offset(0, 1)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 16, color: AppColors.primary),
              const SizedBox(width: 8),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                  letterSpacing: 0.5,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Divider(height: 1, color: AppColors.border),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    );
  }
}

