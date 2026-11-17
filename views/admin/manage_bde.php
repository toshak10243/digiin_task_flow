<?php
require_once "../../app/middleware/AdminMiddleware.php";
require_once "../../app/controllers/UserController.php";

$controller = new UserController();
$success = "";

if(isset($_POST['create'])){
    $res = $controller->createUser($_POST);
    if($res === true) $success="created";
}

if(isset($_GET['remove'])){
    $controller->deactivateUser($_GET['remove']);
    header("Location: manage_bde.php");
}

if(isset($_POST['update'])){
    $controller->updateUserDetails($_POST['user_id'], $_POST['name'], $_POST['password']);
    header("Location: manage_bde.php?updated=1");
}

$bdes = $controller->getUsersByRole('bde');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage BDE Users | Digiink Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    .custom-card { background: #ffffff; border: 1px solid #eef2f6; border-radius: 24px; }
    .input-style { 
        background: #fcfdfe;
        border: 1.5px solid #e2e8f0;
        transition: all 0.2s ease;
    }
    .input-style:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        outline: none;
    }
    /* Table Grid Styling */
    th, td { border: 1px solid #f1f5f9; }
</style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-8">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-2 bg-blue-600 rounded-xl shadow-lg">
                        <span class="material-icons-round text-white">badge</span>
                    </span>
                    BDE User Management
                </h1>
                <p class="text-slate-500 mt-1 font-medium italic">Provision and manage Business Development Executive accounts.</p>
            </div>
            <div class="bg-white px-5 py-2.5 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-3 font-bold text-slate-700">
                <span class="text-xs uppercase text-slate-400 tracking-widest border-r pr-3 border-slate-100">Total BDEs</span>
                <?= count($bdes) ?>
            </div>
        </div>

        <div class="custom-card p-8 shadow-xl shadow-slate-200/50 max-w-4xl border-l-4 border-l-blue-600">
            <h2 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2">
                <span class="material-icons-round text-blue-600 text-sm">person_add</span> Register New BDE
            </h2>
            <form method="POST">
                <input type="hidden" name="role" value="bde">
                <input type="hidden" name="create" value="1">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-500 ml-1 uppercase">Full Name</label>
                        <input type="text" name="name" placeholder="Full Name" class="input-style w-full p-3.5 rounded-xl text-sm font-semibold" required>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-500 ml-1 uppercase">Username</label>
                        <input type="text" name="username" placeholder="Username" class="input-style w-full p-3.5 rounded-xl text-sm font-semibold" required>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-500 ml-1 uppercase">Password</label>
                        <input type="password" name="password" placeholder="Password" class="input-style w-full p-3.5 rounded-xl text-sm font-semibold" required>
                    </div>
                </div>

                <button class="mt-8 bg-blue-600 hover:bg-slate-900 text-white px-10 py-3.5 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg shadow-blue-100 transition-all flex items-center gap-2">
                    Create BDE User <span class="material-icons-round text-sm">rocket_launch</span>
                </button>
            </form>
        </div>

        <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[11px] font-black text-slate-500 uppercase tracking-widest">
                            <th class="p-5 border-slate-100">Full Name</th>
                            <th class="p-5 border-slate-100">Username</th>
                            <th class="p-5 border-slate-100 text-center">Status</th>
                            <th class="p-5 border-slate-100 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach($bdes as $bde): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="p-5 font-bold text-slate-700"><?= $bde['name'] ?></td>
                            <td class="p-5 text-slate-600 font-medium tracking-tight"><?= $bde['username'] ?></td>
                            <td class="p-5 text-center">
                                <span class="bg-emerald-50 text-emerald-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase border border-emerald-100 tracking-tighter">Active</span>
                            </td>
                            <td class="p-5 text-center flex justify-center gap-3 border-none">
                                <button onclick="openEditModal('<?= $bde['id'] ?>','<?= $bde['name'] ?>')" 
                                    class="bg-amber-50 text-amber-600 px-4 py-2 rounded-xl text-[11px] font-black uppercase hover:bg-amber-600 hover:text-white transition-all border border-amber-100 flex items-center gap-1.5">
                                    <span class="material-icons-round text-sm">edit</span> Edit
                                </button>
                                <a href="?remove=<?= $bde['id'] ?>" 
                                    class="bg-rose-50 text-rose-600 px-4 py-2 rounded-xl text-[11px] font-black uppercase hover:bg-rose-600 hover:text-white transition-all border border-rose-100 flex items-center gap-1.5"
                                    onclick="return confirm('Archive this user?')">
                                    <span class="material-icons-round text-sm">person_remove</span> Remove
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if(empty($bdes)): ?>
                <div class="p-16 text-center text-slate-300">
                    <span class="material-icons-round text-6xl">no_accounts</span>
                    <p class="font-bold text-xs uppercase tracking-widest mt-4">No BDE records found</p>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<div id="editModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden justify-center items-center p-4">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-md overflow-hidden border border-white">
        <div class="bg-blue-600 p-8 text-white">
            <h2 class="text-2xl font-black tracking-tight flex items-center gap-3">
                <span class="material-icons-round">edit_square</span> Edit BDE User
            </h2>
            <p class="text-blue-100 text-xs mt-1 font-medium">Update account details for this BDE.</p>
        </div>

        <form method="POST" class="p-8 space-y-6">
            <input type="hidden" name="update" value="1">
            <input type="hidden" id="edit_user_id" name="user_id">

            <div class="space-y-1">
                <label class="text-[11px] font-bold text-slate-500 uppercase ml-1">Account Full Name</label>
                <input type="text" id="edit_name" name="name" class="input-style w-full p-4 rounded-2xl font-bold" required>
            </div>

            <div class="space-y-1">
                <label class="text-[11px] font-bold text-slate-500 uppercase ml-1">Update Password</label>
                <input type="password" name="password" placeholder="Leave empty to keep same" class="input-style w-full p-4 rounded-2xl font-bold">
                <p class="text-[9px] text-slate-400 italic mt-1 px-1">*Enter new password ONLY if you wish to reset it.</p>
            </div>

            <div class="flex flex-col gap-3 pt-4">
                <button class="w-full bg-blue-600 hover:bg-slate-900 text-white py-4 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-blue-100 transition-all">
                    Update Account
                </button>
                <button type="button" onclick="closeModal()" class="w-full bg-slate-100 text-slate-500 py-3 rounded-2xl font-bold text-xs uppercase hover:bg-slate-200 transition-all tracking-widest">
                    Discard Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id,name){
    document.getElementById("edit_user_id").value=id;
    document.getElementById("edit_name").value=name;
    document.getElementById("editModal").classList.remove("hidden");
    document.getElementById("editModal").classList.add("flex");
}
function closeModal(){
    document.getElementById("editModal").classList.add("hidden");
}
</script>

<?php if($success=="created"): ?>
<script>
Swal.fire({
    icon:'success',
    title:'Access Granted!',
    text: 'BDE User Created Successfully',
    confirmButtonColor:'#2563eb',
    customClass: { popup: 'rounded-[2rem]' }
});
</script>
<?php endif; ?>

<?php if(isset($_GET['updated'])): ?>
<script>
Swal.fire({
    icon:'success',
    title:'Profile Synced!',
    text: 'User details have been updated',
    confirmButtonColor:'#2563eb',
    customClass: { popup: 'rounded-[2rem]' }
});
</script>
<?php endif; ?>

</body>
</html>