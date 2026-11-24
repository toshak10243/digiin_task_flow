import 'dart:async';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/presentation/widgets/task_card.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';

class AdminTasksScreen extends StatefulWidget {
  const AdminTasksScreen({super.key});

  @override
  State<AdminTasksScreen> createState() => _AdminTasksScreenState();
}

class _AdminTasksScreenState extends State<AdminTasksScreen> {
  final _searchController = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  Timer? _debounceTimer;
  bool _isLoading = true;
  String _selectedStatus = 'all';
  DateTime? _selectedDate;
  List<TaskModel> _tasks = [];
  int _currentPage = 1;
  int _totalPages = 1;
  int _totalTasks = 0;
  final int _pageSize = 20;

  final List<Map<String, String>> _statusFilters = const [
    {'label': 'All', 'value': 'all'},
    {'label': 'Urgent', 'value': 'urgent'},
    {'label': 'Working', 'value': 'in_progress'},
    {'label': 'Assigned', 'value': 'assigned'},
    {'label': 'Completed', 'value': 'completed'},
    {'label': 'Rework', 'value': 'rework'},
  ];

  @override
  void initState() {
    super.initState();
    _fetchTasks();
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String _) {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(milliseconds: 200), () {
      _currentPage = 1;
      _fetchTasks(page: 1);
    });
  }

  Future<void> _fetchTasks({int page = 1}) async {
    setState(() => _isLoading = true);
    final dateStr = _selectedDate != null ? DateFormat('yyyy-MM-dd').format(_selectedDate!) : 'all';
    final result = await ApiService().getTasksPaginated(
      date: dateStr,
      status: _selectedStatus,
      search: _searchController.text.trim(),
      page: page,
      limit: _pageSize,
    );

    if (!mounted) return;
    setState(() {
      _tasks = result['tasks'] as List<TaskModel>;
      _currentPage = result['page'] as int;
      _totalPages = result['totalPages'] as int;
      _totalTasks = result['total'] as int;
      _isLoading = false;
    });

    if (_scrollController.hasClients) {
      _scrollController.animateTo(
        0,
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeOut,
      );
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? DateTime.now(),
      firstDate: DateTime(2024),
      lastDate: DateTime(2030),
    );

    if (picked != null) {
      setState(() => _selectedDate = picked);
      _currentPage = 1;
      _fetchTasks(page: 1);
    }
  }

  void _clearDateFilter() {
    setState(() => _selectedDate = null);
    _currentPage = 1;
    _fetchTasks(page: 1);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text(
          'Task Inventory',
          style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _fetchTasks,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          // Search & Date Filter Header
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Column(
              children: [
                // Search Field
                TextField(
                  controller: _searchController,
                  onChanged: _onSearchChanged,
                  onSubmitted: (_) => _fetchTasks(),
                  decoration: InputDecoration(
                    hintText: 'Search task key, name, client, expert...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20, color: AppColors.textMuted),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear_rounded, size: 18),
                            onPressed: () {
                              _searchController.clear();
                              _fetchTasks();
                            },
                          )
                        : null,
                  ),
                ),
                const SizedBox(height: 10),

                // Date Filter Bar
                Row(
                  children: [
                    InkWell(
                      onTap: _pickDate,
                      borderRadius: BorderRadius.circular(10),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: _selectedDate != null ? AppColors.primaryLight : AppColors.surfaceMuted,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(
                            color: _selectedDate != null ? AppColors.primary.withOpacity(0.3) : AppColors.border,
                          ),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.calendar_month_rounded,
                              size: 14,
                              color: _selectedDate != null ? AppColors.primary : AppColors.textSecondary,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              _selectedDate != null
                                  ? DateFormat('dd MMM yyyy').format(_selectedDate!)
                                  : 'Filter by Date',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: _selectedDate != null ? AppColors.primary : AppColors.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    if (_selectedDate != null) ...[
                      const SizedBox(width: 6),
                      IconButton(
                        icon: const Icon(Icons.close_rounded, size: 16, color: AppColors.textMuted),
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints(),
                        onPressed: _clearDateFilter,
                      ),
                    ],
                    const Spacer(),
                    Text(
                      _totalTasks > 0 ? '$_totalTasks tasks (Pg $_currentPage/$_totalPages)' : '${_tasks.length} tasks',
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.textMuted),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // Status Pills
                SizedBox(
                  height: 32,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: _statusFilters.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (ctx, i) {
                      final item = _statusFilters[i];
                      final isSelected = _selectedStatus == item['value'];
                      return ChoiceChip(
                        label: Text(
                          item['label']!,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: isSelected ? Colors.white : AppColors.textSecondary,
                          ),
                        ),
                        selected: isSelected,
                        selectedColor: AppColors.primary,
                        backgroundColor: AppColors.surfaceMuted,
                        showCheckmark: false,
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        side: BorderSide(
                          color: isSelected ? AppColors.primary : AppColors.border,
                        ),
                        onSelected: (val) {
                          if (val) {
                            setState(() => _selectedStatus = item['value']!);
                            _currentPage = 1;
                            _fetchTasks(page: 1);
                          }
                        },
                      );
                    },
                  ),
                ),
              ],
            ),
          ),

          // Tasks List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _tasks.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Container(
                              padding: const EdgeInsets.all(20),
                              decoration: BoxDecoration(
                                color: AppColors.surfaceMuted,
                                borderRadius: BorderRadius.circular(30),
                              ),
                              child: const Icon(Icons.assignment_late_outlined, size: 44, color: AppColors.textMuted),
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'No Tasks Found',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              'Try adjusting your search or filters',
                              style: TextStyle(fontSize: 12, color: AppColors.textMuted),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: () => _fetchTasks(page: _currentPage),
                        color: AppColors.primary,
                        child: ListView.separated(
                          controller: _scrollController,
                          padding: const EdgeInsets.all(16),
                          itemCount: _tasks.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (ctx, index) {
                            final task = _tasks[index];
                            return TaskCard(
                              task: task,
                              onTap: () {
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (_) => AdminTaskDetailScreen(taskId: task.id),
                                  ),
                                );
                              },
                            );
                          },
                        ),
                      ),
          ),

          // Pagination Bar (20 per page)
          if (!_isLoading && _tasks.isNotEmpty)
            _buildPaginationBar(),
        ],
      ),
    );
  }

  Widget _buildPaginationBar() {
    if (_totalPages <= 1) return const SizedBox.shrink();

    List<int> pagesToShow = [];
    if (_totalPages <= 5) {
      pagesToShow = List.generate(_totalPages, (i) => i + 1);
    } else {
      if (_currentPage <= 3) {
        pagesToShow = [1, 2, 3, 4, _totalPages];
      } else if (_currentPage >= _totalPages - 2) {
        pagesToShow = [1, _totalPages - 3, _totalPages - 2, _totalPages - 1, _totalPages];
      } else {
        pagesToShow = [1, _currentPage - 1, _currentPage, _currentPage + 1, _totalPages];
      }
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.border)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Showing ${((_currentPage - 1) * _pageSize) + 1} - ${((_currentPage - 1) * _pageSize) + _tasks.length} of $_totalTasks tasks',
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.textSecondary),
              ),
              Text(
                'Page $_currentPage of $_totalPages',
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.primary),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // Prev Button
              OutlinedButton(
                onPressed: _currentPage > 1 ? () => _fetchTasks(page: _currentPage - 1) : null,
                style: OutlinedButton.styleFrom(
                  visualDensity: VisualDensity.compact,
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  side: BorderSide(color: _currentPage > 1 ? AppColors.border : AppColors.borderSubtle),
                ),
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.chevron_left_rounded, size: 16),
                    Text('Prev', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                  ],
                ),
              ),
              const SizedBox(width: 8),

              // Page Numbers: 1, 2, 3 ... N
              Flexible(
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      for (int i = 0; i < pagesToShow.length; i++) ...[
                        if (i > 0 && pagesToShow[i] > pagesToShow[i - 1] + 1)
                          const Padding(
                            padding: EdgeInsets.symmetric(horizontal: 4),
                            child: Text('...', style: TextStyle(color: AppColors.textMuted, fontWeight: FontWeight.bold)),
                          ),
                        InkWell(
                          onTap: () => _fetchTasks(page: pagesToShow[i]),
                          borderRadius: BorderRadius.circular(8),
                          child: Container(
                            margin: const EdgeInsets.symmetric(horizontal: 3),
                            width: 30,
                            height: 30,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: _currentPage == pagesToShow[i] ? AppColors.primary : AppColors.surfaceMuted,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(
                                color: _currentPage == pagesToShow[i] ? AppColors.primary : AppColors.border,
                              ),
                            ),
                            child: Text(
                              '${pagesToShow[i]}',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: _currentPage == pagesToShow[i] ? Colors.white : AppColors.textPrimary,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 8),

              // Next Button
              OutlinedButton(
                onPressed: _currentPage < _totalPages ? () => _fetchTasks(page: _currentPage + 1) : null,
                style: OutlinedButton.styleFrom(
                  visualDensity: VisualDensity.compact,
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  side: BorderSide(color: _currentPage < _totalPages ? AppColors.border : AppColors.borderSubtle),
                ),
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('Next', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                    Icon(Icons.chevron_right_rounded, size: 16),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

