import 'dart:async';
import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_additional_dialog.dart';
import 'package:digiink_app/presentation/screens/bde/bde_create_task_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_feedback_dialog.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

class BdeMyTasksScreen extends StatefulWidget {
  final VoidCallback? onCreateTask;

  const BdeMyTasksScreen({super.key, this.onCreateTask});

  @override
  State<BdeMyTasksScreen> createState() => _BdeMyTasksScreenState();
}

class _BdeMyTasksScreenState extends State<BdeMyTasksScreen> {
  final ApiService _apiService = ApiService();
  final TextEditingController _searchController = TextEditingController();
  final ScrollController _scrollController = ScrollController();

  List<TaskModel> _tasks = [];
  bool _isLoading = true;
  String _selectedStatus = 'all';

  // Pagination
  int _currentPage = 1;
  int _totalPages = 1;
  int _totalTasks = 0;
  final int _pageSize = 20;

  Timer? _searchDebounce;

  final List<Map<String, String>> _statusFilters = [
    {'label': 'All Tasks', 'value': 'all'},
    {'label': 'Today', 'value': 'today'},
    {'label': 'Working', 'value': 'in_progress'},
    {'label': 'In QC', 'value': 'qc'},
    {'label': 'Delivered', 'value': 'delivered'},
    {'label': 'Rework', 'value': 'rework'},
    {'label': 'Completed', 'value': 'completed'},
  ];

  @override
  void initState() {
    super.initState();
    _fetchTasks();
  }

  @override
  void dispose() {
    _searchController.dispose();
    _scrollController.dispose();
    _searchDebounce?.cancel();
    super.dispose();
  }

  Future<void> _fetchTasks({int page = 1}) async {
    setState(() => _isLoading = true);

    String? dateParam;
    String statusParam = _selectedStatus;

    if (_selectedStatus == 'today') {
      dateParam = DateFormat('yyyy-MM-dd').format(DateTime.now());
      statusParam = 'all';
    }

    final res = await _apiService.getBdeTasksPaginated(
      date: dateParam,
      status: statusParam,
      search: _searchController.text.trim(),
      page: page,
      limit: _pageSize,
    );

    if (!mounted) return;
    setState(() {
      _isLoading = false;
      _tasks = (res['tasks'] as List<TaskModel>?) ?? [];
      _totalTasks = res['total'] ?? _tasks.length;
      _currentPage = res['page'] ?? page;
      _totalPages = res['total_pages'] ?? 1;
    });

    if (_scrollController.hasClients) {
      _scrollController.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
    }
  }

  void _onSearchChanged(String val) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 250), () {
      _currentPage = 1;
      _fetchTasks(page: 1);
    });
  }

  void _onStatusChanged(String status) {
    if (_selectedStatus == status) return;
    setState(() {
      _selectedStatus = status;
      _currentPage = 1;
    });
    _fetchTasks(page: 1);
  }

  Future<void> _toggleDelivered(TaskModel t) async {
    final res = await _apiService.toggleDelivered(t.id);
    if (!mounted) return;
    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Delivery status updated'),
          backgroundColor: AppColors.success,
          duration: const Duration(seconds: 2),
        ),
      );
      _fetchTasks(page: _currentPage);
    }
  }

  Future<void> _openDriveLink(String? url) async {
    if (url == null || url.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No Google Drive folder linked')),
      );
      return;
    }
    final uri = Uri.parse(url.trim());
    try {
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'My Tasks',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
            ),
            Text(
              '$_totalTasks tasks (Pg $_currentPage/$_totalPages)',
              style: const TextStyle(fontSize: 10, color: AppColors.textMuted, fontWeight: FontWeight.normal),
            ),
          ],
        ),
        backgroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        actions: [
          IconButton(
            icon: const Icon(Icons.add_circle_outline_rounded, color: AppColors.primary),
            tooltip: 'New Task',
            onPressed: () {
              if (widget.onCreateTask != null) {
                widget.onCreateTask!();
              } else {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const BdeCreateTaskScreen()),
                ).then((_) => _fetchTasks());
              }
            },
          ),
          IconButton(
            icon: const Icon(Icons.refresh_rounded, color: AppColors.textSecondary),
            onPressed: () => _fetchTasks(page: _currentPage),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(color: AppColors.border, height: 1),
        ),
      ),
      body: Column(
        children: [
          // ─── Search & Status Filters ──────────────────────────
          Container(
            color: Colors.white,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Column(
              children: [
                // Instant Search Bar
                TextField(
                  controller: _searchController,
                  onChanged: _onSearchChanged,
                  decoration: InputDecoration(
                    hintText: 'Search Task Key, Project, Client...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20, color: AppColors.textMuted),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear_rounded, size: 18, color: AppColors.textMuted),
                            onPressed: () {
                              _searchController.clear();
                              _onSearchChanged('');
                            },
                          )
                        : null,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  ),
                ),
                const SizedBox(height: 10),

                // Status Filter Pills
                SizedBox(
                  height: 32,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: _statusFilters.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 6),
                    itemBuilder: (context, i) {
                      final item = _statusFilters[i];
                      final isSelected = _selectedStatus == item['value'];
                      return ChoiceChip(
                        label: Text(item['label']!),
                        selected: isSelected,
                        onSelected: (_) => _onStatusChanged(item['value']!),
                        selectedColor: AppColors.primary,
                        backgroundColor: AppColors.surface,
                        labelStyle: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: isSelected ? Colors.white : AppColors.textSecondary,
                        ),
                        side: BorderSide(
                          color: isSelected ? AppColors.primary : AppColors.border,
                        ),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                        visualDensity: VisualDensity.compact,
                      );
                    },
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1, color: AppColors.border),

          // ─── Task List & Pagination ───────────────────────────
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _tasks.isEmpty
                    ? _buildEmptyState()
                    : RefreshIndicator(
                        onRefresh: () => _fetchTasks(page: _currentPage),
                        color: AppColors.primary,
                        child: ListView.separated(
                          controller: _scrollController,
                          padding: const EdgeInsets.all(16),
                          itemCount: _tasks.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (context, index) {
                            final t = _tasks[index];
                            return _buildBdeTaskCard(t);
                          },
                        ),
                      ),
          ),

          // ─── Pagination Bar (1, 2, 3... n) ───────────────────
          if (_totalPages > 1) _buildPaginationBar(),
        ],
      ),
    );
  }

  Widget _buildBdeTaskCard(TaskModel t) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: t.isUrgent ? const Color(0xFFFDA4AF) : AppColors.border,
          width: t.isUrgent ? 1.5 : 1,
        ),
        boxShadow: const [
          BoxShadow(color: Color(0x04000000), blurRadius: 3, offset: Offset(0, 1)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Top row: Key, Urgent Badge, Status Chip
          Row(
            children: [
              Text(
                t.taskKey,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: AppColors.primary,
                  fontFamily: 'monospace',
                ),
              ),
              if (t.isUrgent) ...[
                const SizedBox(width: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDE8EC),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.priority_high_rounded, size: 10, color: Color(0xFFE11D48)),
                      SizedBox(width: 2),
                      Text(
                        'URGENT',
                        style: TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: Color(0xFFE11D48)),
                      ),
                    ],
                  ),
                ),
              ],
              const Spacer(),
              _buildStatusBadge(t.status),
            ],
          ),
          const SizedBox(height: 6),

          // Project Title
          InkWell(
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => AdminTaskDetailScreen(taskId: t.id)),
            ),
            child: Text(
              t.taskName,
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(height: 8),

          // Client, Words, Deadline info
          Row(
            children: [
              const Icon(Icons.person_outline_rounded, size: 13, color: AppColors.textMuted),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  t.clientName,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF0F9D68)),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              Text(
                '${NumberFormat('#,###').format(t.wordCount)}w',
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.textSecondary, fontFamily: 'monospace'),
              ),
              const SizedBox(width: 10),
              const Icon(Icons.schedule_rounded, size: 13, color: AppColors.textMuted),
              const SizedBox(width: 4),
              Text(
                _formatDate(t.deadline),
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: _isPastDeadline(t.deadline) ? const Color(0xFFE11D48) : AppColors.textMuted,
                ),
              ),
            ],
          ),

          // Active Additional Instructions Highlight (if any)
          if (t.hasAdditionalInstructions) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: const Color(0xFFFEF8EC),
                borderRadius: BorderRadius.circular(6),
                border: Border.all(color: const Color(0xFFF6D389)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.new_releases_rounded, size: 14, color: Color(0xFFD99321)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Additional: ${t.additionalInstructions}',
                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: Color(0xFF78350F), height: 1.3),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          ],

          const SizedBox(height: 12),
          const Divider(height: 1, color: AppColors.border),
          const SizedBox(height: 8),

          // ─── Actions Bar ────────────────────────────────────
          Row(
            children: [
              // Open Drive
              IconButton(
                icon: const Icon(Icons.folder_open_rounded, size: 18, color: Color(0xFF3B6CF6)),
                tooltip: 'Open Drive Folder',
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                onPressed: () => _openDriveLink(t.driveClientLink),
              ),

              // Additional Files & Instructions
              IconButton(
                icon: Stack(
                  clipBehavior: Clip.none,
                  children: [
                    const Icon(Icons.rate_review_rounded, size: 18, color: Color(0xFFD99321)),
                    if (t.hasAdditionalInstructions)
                      Positioned(
                        right: -2,
                        top: -2,
                        child: Container(
                          width: 6,
                          height: 6,
                          decoration: const BoxDecoration(
                            color: Color(0xFFD99321),
                            shape: BoxShape.circle,
                          ),
                        ),
                      ),
                  ],
                ),
                tooltip: 'Additional Information / Files',
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                onPressed: () => BdeAdditionalDialog.show(
                  context,
                  t,
                  onUpdated: () => _fetchTasks(page: _currentPage),
                ),
              ),

              // Submit Feedback / Rework
              IconButton(
                icon: const Icon(Icons.replay_rounded, size: 18, color: Color(0xFFE11D48)),
                tooltip: 'Submit Client Feedback / Rework',
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                onPressed: () => BdeFeedbackDialog.show(
                  context,
                  t,
                  onSubmitted: () => _fetchTasks(page: _currentPage),
                ),
              ),

              const Spacer(),

              // Toggle Delivered Button
              InkWell(
                onTap: () => _toggleDelivered(t),
                borderRadius: BorderRadius.circular(6),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: t.deliveredToClient ? const Color(0xFFE2F6EE) : AppColors.surface,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(
                      color: t.deliveredToClient ? const Color(0xFF0F9D68) : AppColors.border,
                    ),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        t.deliveredToClient ? Icons.check_circle_rounded : Icons.local_shipping_outlined,
                        size: 14,
                        color: t.deliveredToClient ? const Color(0xFF065F46) : AppColors.textMuted,
                      ),
                      const SizedBox(width: 4),
                      Text(
                        t.deliveredToClient ? 'DELIVERED' : 'Deliver',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                          color: t.deliveredToClient ? const Color(0xFF065F46) : AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              const SizedBox(width: 6),

              // View Details
              OutlinedButton(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => AdminTaskDetailScreen(taskId: t.id)),
                ),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  side: const BorderSide(color: AppColors.border),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
                child: const Text('Details', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(String status) {
    Color bg = const Color(0xFFF4F4F5);
    Color text = const Color(0xFF52525B);
    String label = status.toUpperCase();

    switch (status.toLowerCase()) {
      case 'new':
        bg = const Color(0xFFF4F4F5);
        text = const Color(0xFF52525B);
        label = 'NEW';
        break;
      case 'assigned':
        bg = const Color(0xFFFDF3E0);
        text = const Color(0xFF92400E);
        label = 'ASSIGNED';
        break;
      case 'in_progress':
        bg = const Color(0xFFE8EDFE);
        text = const Color(0xFF1E40AF);
        label = 'WORKING';
        break;
      case 'qc':
        bg = const Color(0xFFEFEAFD);
        text = const Color(0xFF5B21B6);
        label = 'IN QC';
        break;
      case 'completed':
        bg = const Color(0xFFE2F6EE);
        text = const Color(0xFF065F46);
        label = 'COMPLETED';
        break;
      case 'rework':
        bg = const Color(0xFFFDE8EC);
        text = const Color(0xFF9F1239);
        label = 'REWORK';
        break;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(4)),
      child: Text(
        label,
        style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: text, letterSpacing: 0.3),
      ),
    );
  }

  Widget _buildPaginationBar() {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // Prev
          OutlinedButton(
            onPressed: _currentPage > 1 ? () => _fetchTasks(page: _currentPage - 1) : null,
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              side: const BorderSide(color: AppColors.border),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
              minimumSize: Size.zero,
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.chevron_left_rounded, size: 16),
                Text('Prev', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ],
            ),
          ),

          // Numbers
          Row(
            mainAxisSize: MainAxisSize.min,
            children: _generatePageNumbers().map((p) {
              final isCurrent = p == _currentPage;
              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: 2),
                child: InkWell(
                  onTap: () => _fetchTasks(page: p),
                  borderRadius: BorderRadius.circular(6),
                  child: Container(
                    width: 28,
                    height: 28,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: isCurrent ? AppColors.primary : AppColors.surface,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: isCurrent ? AppColors.primary : AppColors.border),
                    ),
                    child: Text(
                      '$p',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: isCurrent ? Colors.white : AppColors.textSecondary,
                      ),
                    ),
                  ),
                ),
              );
            }).toList(),
          ),

          // Next
          OutlinedButton(
            onPressed: _currentPage < _totalPages ? () => _fetchTasks(page: _currentPage + 1) : null,
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              side: const BorderSide(color: AppColors.border),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
              minimumSize: Size.zero,
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('Next', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                Icon(Icons.chevron_right_rounded, size: 16),
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<int> _generatePageNumbers() {
    final pages = <int>[];
    int start = (_currentPage - 2).clamp(1, _totalPages);
    int end = (start + 4).clamp(1, _totalPages);
    if (end - start < 4) {
      start = (end - 4).clamp(1, _totalPages);
    }
    for (int i = start; i <= end; i++) {
      pages.add(i);
    }
    return pages;
  }

  Widget _buildEmptyState() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(16),
              decoration: const BoxDecoration(
                color: Color(0xFFF4F4F5),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.assignment_outlined, size: 40, color: AppColors.textMuted),
            ),
            const SizedBox(height: 14),
            const Text(
              'No tasks found',
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
            ),
            const SizedBox(height: 6),
            const Text(
              'No tasks matching your current filter criteria.',
              style: TextStyle(fontSize: 12, color: AppColors.textMuted),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ElevatedButton.icon(
              onPressed: () {
                if (widget.onCreateTask != null) {
                  widget.onCreateTask!();
                } else {
                  Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const BdeCreateTaskScreen()),
                  ).then((_) => _fetchTasks());
                }
              },
              icon: const Icon(Icons.add_rounded, size: 16, color: Colors.white),
              label: const Text('Create New Task', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _formatDate(String dateStr) {
    if (dateStr.isEmpty) return 'No date';
    try {
      final dt = DateTime.parse(dateStr);
      return DateFormat('dd MMM, hh:mm a').format(dt);
    } catch (_) {
      return dateStr;
    }
  }

  bool _isPastDeadline(String dateStr) {
    try {
      return DateTime.parse(dateStr).isBefore(DateTime.now());
    } catch (_) {
      return false;
    }
  }
}

