import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';

class BdeAdditionalDialog extends StatefulWidget {
  final TaskModel task;
  final VoidCallback? onUpdated;

  const BdeAdditionalDialog({
    super.key,
    required this.task,
    this.onUpdated,
  });

  static Future<void> show(BuildContext context, TaskModel task, {VoidCallback? onUpdated}) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => BdeAdditionalDialog(task: task, onUpdated: onUpdated),
    );
  }

  @override
  State<BdeAdditionalDialog> createState() => _BdeAdditionalDialogState();
}

class _BdeAdditionalDialogState extends State<BdeAdditionalDialog> {
  final ApiService _apiService = ApiService();
  late final TextEditingController _instructionsController;
  late final TextEditingController _linkController;
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _instructionsController = TextEditingController(text: widget.task.additionalInstructions);
    _linkController = TextEditingController(text: widget.task.driveAdditionalLink ?? '');
  }

  @override
  void dispose() {
    _instructionsController.dispose();
    _linkController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final instructions = _instructionsController.text.trim();
    final link = _linkController.text.trim();

    if (instructions.isEmpty && link.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter additional instructions or a link'),
          backgroundColor: AppColors.error,
        ),
      );
      return;
    }

    setState(() => _isSaving = true);

    final res = await _apiService.saveAdditionalFiles(
      taskId: widget.task.id,
      additionalInstructions: instructions,
      additionalLink: link,
    );

    if (!mounted) return;
    setState(() => _isSaving = false);

    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Additional instructions updated! OM & Expert notified.'),
          backgroundColor: AppColors.success,
        ),
      );
      widget.onUpdated?.call();
      Navigator.pop(context);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Failed to update'),
          backgroundColor: AppColors.error,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.task;

    return Container(
      padding: EdgeInsets.only(
        top: 20,
        left: 20,
        right: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            // Handle bar
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.border,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Header
            Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDF3E0),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.rate_review_rounded, size: 20, color: Color(0xFFD99321)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Additional Information & Files',
                        style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                      ),
                      Text(
                        'Supplemental updates for ${t.taskKey}',
                        style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close_rounded, size: 20, color: AppColors.textMuted),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
            const SizedBox(height: 16),
            const Divider(height: 1, color: AppColors.border),
            const SizedBox(height: 16),

            // Task Summary Card
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        t.taskKey,
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.primary, fontFamily: 'monospace'),
                      ),
                      Text(
                        '${t.wordCount} Words',
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.textSecondary),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    t.taskName,
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.textPrimary),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  if (t.clientName.isNotEmpty && t.clientName != 'N/A') ...[
                    const SizedBox(height: 2),
                    Text(
                      'Client: ${t.clientName}',
                      style: const TextStyle(fontSize: 11, color: Color(0xFF0F9D68), fontWeight: FontWeight.w600),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Highlighted current additional notes if exists
            if (t.hasAdditionalInstructions) ...[
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFEF8EC),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFFF6D389)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Row(
                      children: [
                        Icon(Icons.new_releases_rounded, size: 14, color: Color(0xFFD99321)),
                        SizedBox(width: 6),
                        Text(
                          'CURRENT ACTIVE ADDITIONAL INSTRUCTIONS',
                          style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Color(0xFF92400E)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      t.additionalInstructions,
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF78350F), height: 1.4),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
            ],

            // Input textarea
            const Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Additional Instructions / Client Brief Update',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                ),
                Text(
                  '(Reaches OM & Team)',
                  style: TextStyle(fontSize: 10, color: Color(0xFFD99321), fontWeight: FontWeight.w700),
                ),
              ],
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _instructionsController,
              maxLines: 4,
              decoration: const InputDecoration(
                hintText: 'Type any client updates, extra requirements, specific chapter additions, or instructions here...',
              ),
            ),
            const SizedBox(height: 14),

            // Additional Drive Link
            const Text(
              'Supplemental Google Drive Link (Optional)',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _linkController,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                hintText: 'https://drive.google.com/drive/folders/...',
                prefixIcon: Icon(Icons.link_rounded, size: 18, color: AppColors.textMuted),
              ),
            ),
            const SizedBox(height: 20),

            // Action Buttons
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      side: const BorderSide(color: AppColors.border),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    child: const Text('Cancel', style: TextStyle(color: AppColors.textSecondary, fontWeight: FontWeight.bold)),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 2,
                  child: ElevatedButton.icon(
                    onPressed: _isSaving ? null : _save,
                    icon: _isSaving
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Icon(Icons.send_rounded, size: 16, color: Colors.white),
                    label: Text(
                      _isSaving ? 'Updating...' : 'Update & Notify Team',
                      style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white),
                    ),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFFD99321),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      elevation: 0,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

