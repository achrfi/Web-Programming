<?php
session_start();

$host = 'localhost';
$user = 'root';
$password = '';
$database = 'perpustakaan';
$tahunMin = 1900;
$tahunMax = 2026;

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectStatus(string $status): void
{
    header('Location: index.php?status=' . $status, true, 303);
    exit();
}

function redirectError(array $input = [], array $errors = [], string $message = 'Terjadi kesalahan saat menyimpan data buku.'): void
{
    $_SESSION['old_input'] = $input;
    $_SESSION['form_errors'] = $errors;
    $_SESSION['flash_message'] = $message;
    redirectStatus('error');
}

function positiveId($value)
{
    return is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
}

// Token disertakan pada form tambah, edit, dan hapus.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$pesan = '';
$jenisPesan = '';

if ($isPost) {
    $action = $_POST['action'] ?? 'create';
    if (!is_string($action) || !in_array($action, ['create', 'update', 'delete'], true)) {
        redirectError([], [], 'Aksi tidak valid. Silakan ulangi dari daftar buku.');
    }

    $input = ['action' => $action, 'id' => is_string($_POST['id'] ?? null) ? $_POST['id'] : ''];
    foreach (['judul', 'penulis', 'tahun', 'stok'] as $field) {
        $input[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        redirectError($action === 'delete' ? [] : $input, [], 'Sesi form tidak valid. Silakan kirim ulang form.');
    }

    $id = positiveId($input['id']);
    if (($action === 'update' || $action === 'delete') && $id === false) {
        redirectError($action === 'update' ? $input : [], [], 'ID buku tidak valid. Pilih buku dari daftar.');
    }

    // Hapus tidak memerlukan field judul, penulis, tahun, dan stok.
    if ($action !== 'delete') {
        $judul = $input['judul'];
        $penulis = $input['penulis'];
        $tahun = $input['tahun'];
        $stok = $input['stok'];
        $errors = [];
        if (mb_strlen(trim($judul), 'UTF-8') < 3) {
            $errors['judul'] = 'Judul buku harus diisi dan minimal 3 karakter.';
        } elseif (mb_strlen($judul, 'UTF-8') > 150) {
            $errors['judul'] = 'Judul buku maksimal 150 karakter.';
        }
        if (mb_strlen(trim($penulis), 'UTF-8') < 3) {
            $errors['penulis'] = 'Penulis buku harus diisi dan minimal 3 karakter.';
        } elseif (mb_strlen($penulis, 'UTF-8') > 100) {
            $errors['penulis'] = 'Penulis buku maksimal 100 karakter.';
        }
        if (trim($tahun) === '' || filter_var($tahun, FILTER_VALIDATE_INT) === false
            || $tahun < $tahunMin || $tahun > $tahunMax) {
            $errors['tahun'] = "Tahun terbit harus diisi antara $tahunMin dan $tahunMax.";
        }
        if (trim($stok) === '' || filter_var($stok, FILTER_VALIDATE_INT) === false || $stok < 0) {
            $errors['stok'] = 'Stok harus diisi, berupa bilangan bulat, dan minimal 0.';
        } elseif ($stok > 2147483647) {
            $errors['stok'] = 'Stok maksimal 2147483647.';
        }
        if (!empty($errors)) {
            redirectError($input, $errors);
        }
    }
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Koneksi database gagal. Silakan coba lagi.');
}

if ($isPost) {
    try {
        if ($action === 'delete') {
            $stmt = $conn->prepare('DELETE FROM buku WHERE ID = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                redirectError([], [], 'Buku tidak ditemukan atau sudah dihapus.');
            }
            $status = 'deleted';
        } else {
            if ($action === 'update') {
                // Cek keberadaan: menyimpan data tanpa perubahan tetap sukses.
                $check = $conn->prepare('SELECT ID FROM buku WHERE ID = :id');
                $check->execute([':id' => $id]);
                if ($check->fetch(PDO::FETCH_ASSOC) === false) {
                    redirectError($input, [], 'Buku tidak ditemukan atau sudah dihapus.');
                }
                $stmt = $conn->prepare('UPDATE buku SET Judul = :judul, Penulis = :penulis, tahun = :tahun, stok = :stok WHERE ID = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $status = 'updated';
            } else {
                // Pertahankan INSERT PDO dan status sukses penambahan.
                $stmt = $conn->prepare('INSERT INTO buku (Judul, Penulis, tahun, stok) VALUES (:judul, :penulis, :tahun, :stok)');
                $status = 'success';
            }
            $stmt->bindParam(':judul', $judul);
            $stmt->bindParam(':penulis', $penulis);
            $stmt->bindParam(':tahun', $tahun);
            $stmt->bindParam(':stok', $stok);
            $stmt->execute();
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        redirectError($action === 'delete' ? [] : $input, [], $action === 'delete'
            ? 'Buku gagal dihapus. Buku mungkin masih digunakan pada data peminjaman.'
            : 'Terjadi kesalahan saat menyimpan data buku.');
    }

    unset($_SESSION['form_errors'], $_SESSION['old_input'], $_SESSION['flash_message']);
    redirectStatus($status);
}

// Flash dibaca oleh GET setelah redirect, di luar blok POST.
$hasOldInput = isset($_SESSION['old_input']);
$formErrors = $_SESSION['form_errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['form_errors'], $_SESSION['old_input'], $_SESSION['flash_message']);

$status = $_GET['status'] ?? '';
if ($status === 'success') {
    $pesan = 'Data buku berhasil disimpan.';
    $jenisPesan = 'success';
} elseif ($status === 'updated') {
    $pesan = 'Data buku berhasil diperbarui.';
    $jenisPesan = 'success';
} elseif ($status === 'deleted') {
    $pesan = 'Data buku berhasil dihapus.';
    $jenisPesan = 'success';
} elseif ($status === 'error') {
    $pesan = $flashMessage !== '' ? $flashMessage : 'Terjadi kesalahan saat menyimpan data buku.';
    $jenisPesan = 'danger';
}

// Tautan Edit tetap berfungsi jika JavaScript dinonaktifkan.
if (!$hasOldInput && isset($_GET['edit'])) {
    $editId = positiveId($_GET['edit']);
    $book = false;
    if ($editId !== false) {
        $stmt = $conn->prepare('SELECT * FROM buku WHERE ID = :id');
        $stmt->execute([':id' => $editId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($book === false) {
        $pesan = 'Buku tidak ditemukan atau ID buku tidak valid.';
        $jenisPesan = 'danger';
    } else {
        $oldInput = ['action' => 'update', 'id' => (string) $book['ID'], 'judul' => $book['Judul'],
            'penulis' => $book['Penulis'], 'tahun' => $book['tahun'], 'stok' => $book['stok']];
    }
}
$isEditing = ($oldInput['action'] ?? '') === 'update';
$query = $conn->query('SELECT * FROM buku order by created_at desc');
$bukus = $query->fetchAll(PDO::FETCH_ASSOC);
// JavaScript memakai data database yang sama, bukan array contoh terpisah.
$jsBooks = [];
foreach ($bukus as $book) {
    $jsBooks[] = ['id' => (string) $book['ID'], 'judul' => $book['Judul'], 'penulis' => $book['Penulis'],
        'tahun' => $book['tahun'], 'stok' => $book['stok']];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Praktk Boostrap</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
</head>

<body>
    <header class="bg-secondary text-white p-4">
        <h1 class="h3 m-0">Website Praktik</h1>
    </header>

    <nav class="bg-info text-white p-3">Navigasi</nav>
    <div class="container-fluid my-4">
        <div class="row min-vh-100">
            <aside class="col-md-3">
                <div class="bg-light border rounded p-3">
                    <h2>Menu</h2>
                    <ul class="list-group">
                        <li class="list-group-item"><a href="index.html"
                                class="text-decoration-none text-black">Dashboard</a></li>
                        <li class="list-group-item"><a href="#" class="text-decoration-none text-black">Daftar Buku</a>
                        </li>
                        <li class="list-group-item"><a href="#" class="text-decoration-none text-black">Form
                                Peminjam</a></li>
                    </ul>
                </div>
            </aside>

            <main class="col-md-9">
                <section class="border rounded p-3">
                    <h2 id="formTitle"><?= $isEditing ? 'Form Edit Buku' : 'Form Tambah Buku' ?></h2>
                    <form action="index.php" method="POST" id="formBuku" novalidate>
                        <input type="hidden" name="id" value="<?= e($oldInput['id'] ?? '') ?>" />
                        <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>" />
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                        <div class="row g-3">
                            <!-- Judul Buku -->
                            <div class="col-md-6 mb-3">
                                <label for="judul" class="form-label">Judul Buku</label>
                                <input type="text" class="form-control <?= isset($formErrors['judul']) ? 'is-invalid' : '' ?>" id="judul" name="judul" aria-invalid="<?= isset($formErrors['judul']) ? 'true' : 'false' ?>" aria-describedby="judul-error" required minlength="3" value="<?= e($oldInput['judul'] ?? '') ?>" >
                                <div id="judul-error" class="invalid-feedback">
                                    <?= e($formErrors['judul'] ?? 'Judul buku harus diisi dan minimal 3 karakter.') ?>
                                </div>
                            </div>

                            <!-- Penulis -->
                            <div class="col-md-6 mb-3">
                                <label for="penulis" class="form-label">Penulis</label>
                                <input type="text" class="form-control <?= isset($formErrors['penulis']) ? 'is-invalid' : '' ?>" id="penulis" name="penulis" aria-invalid="<?= isset($formErrors['penulis']) ? 'true' : 'false' ?>" aria-describedby="penulis-error" required minlength="3" value="<?= e($oldInput['penulis'] ?? '') ?>" >
                                <div id="penulis-error" class="invalid-feedback">
                                    <?= e($formErrors['penulis'] ?? 'Penulis buku harus diisi dan minimal 3 karakter.') ?>
                                </div>
                            </div>

                            <!-- Tahun Terbit -->
                            <div class="col-md-6 mb-3">
                                <label for="tahun" class="form-label">Tahun Terbit</label>
                                <input type="number" class="form-control <?= isset($formErrors['tahun']) ? 'is-invalid' : '' ?>" id="tahun" name="tahun" aria-invalid="<?= isset($formErrors['tahun']) ? 'true' : 'false' ?>" aria-describedby="tahun-error" required min="<?= $tahunMin ?>" max="<?= $tahunMax ?>" step="1" value="<?= e($oldInput['tahun'] ?? '') ?>" >
                                <div id="tahun-error" class="invalid-feedback">
                                    <?= e($formErrors['tahun'] ?? "Tahun terbit harus diisi antara $tahunMin dan $tahunMax.") ?>
                                </div>
                            </div>

                            <!-- Stok -->
                            <div class="col-md-6 mb-3">
                                <label for="stok" class="form-label">Stok</label>
                                <input type="number" class="form-control <?= isset($formErrors['stok']) ? 'is-invalid' : '' ?>" id="stok" name="stok" aria-invalid="<?= isset($formErrors['stok']) ? 'true' : 'false' ?>" aria-describedby="stok-error" required min="0" step="1" value="<?= e($oldInput['stok'] ?? '') ?>" >
                                <div id="stok-error" class="invalid-feedback">
                                    <?= e($formErrors['stok'] ?? 'Stok harus diisi, berupa bilangan bulat, dan minimal 0.') ?>
                                </div>
                            </div>
                        
                        </div>

                        <button type="submit" id="submitBuku" class="btn btn-primary"><?= $isEditing ? 'Simpan Perubahan' : 'Submit' ?></button>
                        <a href="index.php" id="batalEdit" class="btn btn-secondary<?= $isEditing ? '' : ' d-none' ?>">Batal Edit</a>
                    </form>
                </section>

                <section class="border rounded p-3 mt-4">
                    <h2>Daftar Buku</h2>
                    <?php if ($pesan !== ''): ?>
                        <div class="alert alert-<?= e($jenisPesan) ?>" role="alert">
                            <?= e($pesan) ?>
                        </div>
                    <?php endif; ?>
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul</th>
                                <th>Penulis</th>
                                <th>Tahun Terbit</th>
                                <th>Stok</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bukus)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">Tidak ada data buku.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bukus as $index => $buku): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= e($buku['Judul']) ?></td>
                                        <td><?= e($buku['Penulis']) ?></td> 
                                        <td><?= e($buku['tahun']) ?></td>
                                        <td><?= e($buku['stok']) ?></td>
                                        <td class="text-nowrap">
                                            <a href="index.php?edit=<?= e($buku['ID']) ?>"
                                                class="btn btn-sm btn-warning"
                                                data-book-id="<?= e($buku['ID']) ?>"
                                                onclick="return editBuku(this.dataset.bookId)">Edit</a>
                                            <form action="index.php" method="POST" class="d-inline" onsubmit="return deleteBuku(this)">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= e($buku['ID']) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </td>
                                           
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </main>
        </div>
    </div>

    <footer class="bg-dark-subtle text-black text-center p-2 m-4">
        <p class="mb-0">Copyright &copy; 2023 Website Praktik. All rights reserved.</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let bukus = <?= json_encode($jsBooks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
        let nextId = bukus.reduce((maxId, buku) => Math.max(maxId, Number(buku.id)), 0) + 1;
        const tabelBuku = document.getElementById("daftarBuku");

        function renderBuku() {
            const daftarBuku = document.getElementById("daftarBuku");
            // Halaman PHP sudah merender tabel dari database.
            if (!daftarBuku) {
                return;
            }
            daftarBuku.innerHTML = "";
            bukus.forEach((buku, index) => {
                const row = document.createElement("tr");
                row.innerHTML = `
                    <td>${index + 1}</td>
                    <td>${buku.judul}</td>
                    <td>${buku.penulis}</td>
                    <td>${buku.tahun}</td>
                    <td>${buku.stok}</td>
                    <td>
                        <button class="btn btn-sm btn-warning" onclick="editBuku(${buku.id})">Edit</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteBuku(${buku.id})">Delete</button>
                    </td>
                `;
                daftarBuku.appendChild(row);
            });
        }
        renderBuku();
        const form = document.getElementById("formBuku");

        function editBuku(id) {
            const buku = bukus.find(buku => String(buku.id) === String(id));
            if (!buku || !form) {
                return true; // Ikuti tautan GET jika data belum tersedia di JavaScript.
            }
            form.elements["id"].value = buku.id;
            form.elements["action"].value = "update";
            for (const field of ["judul", "penulis", "tahun", "stok"]) {
                const input = form.elements[field];
                input.value = buku[field];
                input.classList.remove("is-invalid");
                input.setAttribute("aria-invalid", "false");
            }
            form.classList.remove("was-validated");
            document.getElementById("formTitle").textContent = "Form Edit Buku";
            document.getElementById("submitBuku").textContent = "Simpan Perubahan";
            document.getElementById("batalEdit").classList.remove("d-none");
            form.scrollIntoView({ behavior: "smooth", block: "start" });
            form.elements["judul"].focus({ preventScroll: true });
            return false;
        }

        function deleteBuku(deleteForm) {
            // Konfirmasi saja; browser mengirim form POST hapus ke PHP.
            return window.confirm("Yakin ingin menghapus buku ini?");
        }


        form.addEventListener("submit", function (event) {
            // Form POST diteruskan ke PHP, termasuk saat input tidak valid.
            // Jangan ubah array demo atau reset input sebelum POST terkirim.
            if (form.method.toLowerCase() === "post") {
                return;
            }
            event.preventDefault();
            if (!form.checkValidity()) {
                form.classList.add("was-validated");
                return;
            }

            const id = parseInt(form.elements["id"].value);
            const judul = form.elements["judul"].value;
            const penulis = form.elements["penulis"].value;
            const tahun = parseInt(form.elements["tahun"].value);
            const stok = parseInt(form.elements["stok"].value);

            if (id) {
                // Edit existing buku
                const bukuIndex = bukus.findIndex(buku => buku.id === id);
                if (bukuIndex !== -1) {
                    bukus[bukuIndex] = { id, judul, penulis, tahun, stok };
                }
            } else {
                // Add new buku
                bukus.push({ id: nextId++, judul, penulis, tahun, stok });
            }

            renderBuku();
            form.reset();
            form.classList.remove("was-validated");
        });

    </script>
</body>



</html>
