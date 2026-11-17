<?php
$zipPath = $_GET['file'] ?? '';

if(!file_exists($zipPath)){
    die("ZIP not found");
}

$zip = new ZipArchive;

// Helper function for dynamic icons based on extension
function getFileIcon($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf' => ['icon' => 'picture_as_pdf', 'color' => 'text-rose-500'],
        'doc', 'docx' => ['icon' => 'description', 'color' => 'text-blue-600'],
        'xls', 'xlsx' => ['icon' => 'table_view', 'color' => 'text-emerald-600'],
        'jpg', 'jpeg', 'png', 'gif' => ['icon' => 'image', 'color' => 'text-purple-500'],
        'zip', 'rar' => ['icon' => 'inventory_2', 'color' => 'text-amber-500'],
        'txt' => ['icon' => 'article', 'color' => 'text-slate-500'],
        default => ['icon' => 'insert_drive_file', 'color' => 'text-slate-400'],
    };
}

if ($zip->open($zipPath) === TRUE):
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Explorer - ZIP Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .window-glass {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }
        .explorer-card {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            width: 95%;
            max-width: 1000px;
            height: 85vh;
            display: flex;
            flex-direction: column;
        }
        .file-row:hover .checkbox-custom { opacity: 1; }
        .checkbox-custom { opacity: 0.3; transition: opacity 0.2s; }
        .checkbox-custom:checked { opacity: 1; }
        
        /* Custom scrollbar for Windows look */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="p-4">

<div class="explorer-card window-glass rounded-3xl animate-in fade-in zoom-in duration-300">
    
    <div class="flex justify-between items-center px-6 py-3 border-b border-slate-200/50 select-none">
        <div class="flex items-center gap-3">
            <img src="../../assets/logo.png" class="h-6 w-auto object-contain" alt="Digiink Logo" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/a/af/Folder_Icon.svg'">
            <span class="text-xs font-bold text-slate-600 tracking-tight italic"><?= basename($zipPath) ?> — File Explorer</span>
        </div>
        <div class="flex items-center gap-6">
            <span class="material-icons-round text-slate-400 text-lg cursor-default">remove</span>
            <span class="material-icons-round text-slate-400 text-base cursor-default">check_box_outline_blank</span>
            <div class="bg-rose-500/10 hover:bg-rose-500 hover:text-white transition-all p-1 rounded-md cursor-pointer group" onclick="window.history.back()">
                <span class="material-icons-round text-rose-500 group-hover:text-white text-lg">close</span>
            </div>
        </div>
    </div>

    <div class="bg-white/40 p-4 border-b border-slate-200/50 flex flex-wrap gap-4 items-center">
        <div class="flex gap-2 mr-4">
            <button class="p-2 hover:bg-white rounded-lg transition-all border border-transparent hover:border-slate-200 shadow-sm group">
                <span class="material-icons-round text-slate-600 group-active:scale-90 transition-transform">arrow_back</span>
            </button>
            <button class="p-2 hover:bg-white rounded-lg transition-all border border-transparent hover:border-slate-200 shadow-sm">
                <span class="material-icons-round text-slate-600">arrow_forward</span>
            </button>
            <button class="p-2 hover:bg-white rounded-lg transition-all border border-transparent hover:border-slate-200 shadow-sm">
                <span class="material-icons-round text-slate-600">arrow_upward</span>
            </button>
        </div>

        <div class="flex-1 flex items-center bg-white/60 border border-slate-200 rounded-lg px-4 py-1.5 gap-2 text-sm text-slate-500 font-medium overflow-hidden">
            <span class="material-icons-round text-blue-500 text-sm">home</span>
            <span class="material-icons-round text-xs">chevron_right</span>
            <span class="whitespace-nowrap">This PC</span>
            <span class="material-icons-round text-xs">chevron_right</span>
            <span class="whitespace-nowrap">Digiink Tasks</span>
            <span class="material-icons-round text-xs text-slate-400">chevron_right</span>
            <span class="text-slate-900 font-bold truncate"><?= basename($zipPath) ?></span>
        </div>

        <div class="relative w-full md:w-64">
            <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
            <input type="text" id="search" placeholder="Search in ZIP..." 
                class="w-full pl-10 pr-4 py-1.5 bg-white/60 border border-slate-200 rounded-lg text-sm focus:border-blue-500 outline-none transition-all placeholder:text-slate-400">
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-6">
        <form method="POST" action="download_handler.php" id="zipForm">
            <div class="flex items-center gap-4 mb-4 px-4 py-2 bg-indigo-50/50 rounded-xl border border-indigo-100/50">
                <input type="checkbox" id="selectAll" onclick="toggleAll(this)" 
                    class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                <label for="selectAll" class="text-xs font-black text-indigo-600 uppercase tracking-widest cursor-pointer select-none">Select All Items</label>
                <div class="ml-auto text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                    <?= $zip->numFiles ?> items found
                </div>
            </div>

            <div id="fileList" class="grid grid-cols-1 gap-1">
                <?php 
                for ($i = 0; $i < $zip->numFiles; $i++): 
                    $fileName = $zip->getNameIndex($i);
                    $stat = $zip->statIndex($i);
                    $fileDetails = getFileIcon($fileName);
                ?>
                <div class="file-row flex justify-between items-center px-4 py-3 rounded-xl hover:bg-white hover:shadow-sm border border-transparent hover:border-slate-100 transition-all cursor-default select-none group">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" name="files[]" value="<?= $fileName ?>" 
                            class="checkbox-custom w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        
                        <div class="p-2 rounded-lg bg-white shadow-sm border border-slate-50">
                            <span class="material-icons-round <?= $fileDetails['color'] ?> text-2xl"><?= $fileDetails['icon'] ?></span>
                        </div>

                        <div class="flex flex-col">
                            <span class="text-sm font-bold text-slate-700 leading-tight truncate max-w-md"><?= $fileName ?></span>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">File Item</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-6">
                        <span class="text-[11px] font-bold text-slate-500 font-mono">
                            <?= round($stat['size']/1024, 2) ?> KB
                        </span>
                        <div class="opacity-0 group-hover:opacity-100 transition-opacity">
                             <span class="material-icons-round text-slate-300 text-sm cursor-pointer hover:text-blue-500">more_vert</span>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <input type="hidden" name="zip" value="<?= htmlspecialchars($zipPath) ?>">
        </form>
    </div>

    <div class="bg-slate-50/80 p-5 border-t border-slate-200/50 flex flex-wrap justify-between items-center gap-4">
        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
            <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
            System Ready
        </div>

        <div class="flex gap-3">
            <button onclick="document.getElementById('zipForm').submit()" 
                class="bg-indigo-600 hover:bg-slate-900 text-white px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest shadow-lg shadow-indigo-200 transition-all flex items-center gap-2 group active:scale-95">
                <span class="material-icons-round text-sm group-hover:animate-bounce">file_download</span>
                Extract Selected
            </button>

            <a href="<?= $zipPath ?>" 
                class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all flex items-center gap-2 active:scale-95">
                <span class="material-icons-round text-sm text-emerald-500">inventory_2</span>
                Full ZIP
            </a>
        </div>
    </div>
</div>

<script>
// Windows style real-time search
document.getElementById("search").addEventListener("keyup", function(){
    let val = this.value.toLowerCase();
    document.querySelectorAll(".file-row").forEach(div => {
        const fileName = div.innerText.toLowerCase();
        div.style.display = fileName.includes(val) ? "flex" : "none";
    });
});

// Select All Toggle
function toggleAll(source){
    document.querySelectorAll('input[name="files[]"]').forEach(cb => {
        cb.checked = source.checked;
        cb.style.opacity = source.checked ? "1" : "0.3";
    });
}

// Visual checkbox feedback
document.querySelectorAll('input[name="files[]"]').forEach(cb => {
    cb.addEventListener('change', function() {
        this.style.opacity = this.checked ? "1" : "0.3";
    });
});
</script>

</body>
</html>

<?php
$zip->close();
else:
    echo '<div class="h-screen flex items-center justify-center bg-slate-100 flex-col gap-4">
            <span class="material-icons-round text-rose-500 text-6xl">error_outline</span>
            <h2 class="text-xl font-bold text-slate-700">Access Denied: ZIP File Corrupted</h2>
            <a href="javascript:history.back()" class="text-blue-500 font-bold hover:underline">Go Back</a>
          </div>';
endif;
?>