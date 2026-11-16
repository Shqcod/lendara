import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { writeFile } from 'node:fs/promises';

test('model asli: nasabah kirim, gagal koneksi, retry, revisi/kirim ulang dan persetujuan L1/L2', async ({ browser }) => {
 test.setTimeout(480000);
 function fixture(op: string, input: object = {}) { return JSON.parse(execFileSync('php',['tests/Support/model-browser.php',op],
  {cwd:'../backend',env:{...process.env,APP_ENV:'testing'},input:JSON.stringify(input),encoding:'utf8',timeout:60000})); }
 const password=randomUUID(), users=fixture('users',{password});
 const contexts=await Promise.all([0,1,2,3].map(()=>browser.newContext()));
 const [customer,verifier,one,two]=await Promise.all(contexts.map(c=>c.newPage()));
 let loan: {id:number;application_number:string};
 const evidence: object[]=[];
 const runtime=process.env.RUNTIME_GRANTS_TEST==='1';
 const prefix=runtime?'runtime-':'';
 async function login(page: Page,key:string) {
  await page.goto('/'); await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.',{exact:true})).toBeVisible();
  await page.getByLabel('Email',{exact:true}).fill(users[key].email);await page.getByLabel('Kata sandi',{exact:true}).fill(password);
  await page.getByRole('button',{name:'Masuk',exact:true}).click();
  await page.getByRole('link',{name:key==='customer'?'Buka ruang nasabah':'Buka antrean internal'}).click();
 }
 function inspect() { const data=fixture('inspect',{application_id:loan.id});evidence.push(data);return data; }
 function worker(fail=false) {
  const {queue}=fixture('route-job',{application_id:loan.id});
  const env={...process.env,APP_ENV:'testing',...(fail?{ML_SERVICE_URL:process.env.MODEL_BROWSER_FAILURE_URL!}:{})};
  if(runtime) Object.assign(env,{DB_CONNECTION:'mysql',DB_HOST:'127.0.0.1',DB_PORT:process.env.RUNTIME_DB_PORT,
   DB_DATABASE:'kkp_pinjaman_test',DB_USERNAME:process.env.RUNTIME_DB_USERNAME,DB_PASSWORD:process.env.RUNTIME_DB_PASSWORD,
   DB_URL:'',CACHE_STORE:'database',SESSION_CONNECTION:'mysql',DB_QUEUE_CONNECTION:'mysql'});
  if(runtime) {
   const identity=JSON.parse(execFileSync('php',['tests/Support/runtime-context.php'],{cwd:'../backend',env,encoding:'utf8',timeout:30000}));
   expect(identity.restricted_identity_verified).toBe(true);evidence.push({worker_identity:identity});
  }
  execFileSync('php',['artisan','queue:work','--env=testing',`--queue=${queue}`,'--stop-when-empty','--max-jobs=1','--max-time=45','--tries=1','--timeout=0'],
   {cwd:'../backend',env,encoding:'utf8',timeout:60000});
  return inspect();
 }
 async function claim(page:Page) {
  await page.goto('/internal');await expect(page.getByRole('button',{name:'Cari pengajuan',exact:true})).toBeEnabled();
  await page.getByLabel('Nomor pengajuan',{exact:true}).fill(loan.application_number);await page.getByRole('button',{name:'Cari pengajuan',exact:true}).click();
  await page.getByRole('button',{name:`Lihat ${loan.application_number}`,exact:true}).click();
  await page.getByRole('button',{name:'Ambil tugas ini',exact:true}).click();await expect(page.getByRole('button',{name:'Lepas tugas',exact:true})).toBeEnabled();
 }
 async function submit(button:string) {
  await customer.getByRole('button',{name:button,exact:true}).click();await customer.getByRole('checkbox').check();
  const done=customer.waitForResponse(r=>r.url().endsWith('/submissions')&&r.request().method()==='POST');
  await customer.getByRole('button',{name:'Ya, kirim pengajuan',exact:true}).click();
  const response=await done;expect(response.status()).toBe(202);const body=await response.json();
  expect(JSON.stringify(body)).not.toMatch(/anomaly_checks|is_anomaly|"score"|artifact_hash/);
 }
 async function action(page:Page,button:string,confirm:string) {
  await page.getByRole('button',{name:button,exact:true}).click();await page.getByRole('button',{name:confirm,exact:true}).click();
  await expect(page.getByRole('heading',{name:loan.application_number,exact:true})).toHaveCount(0);
 }
 try {
  await login(customer,'customer');await expect(customer.getByRole('button',{name:'Simpan profil',exact:true})).toBeEnabled();
  const created=customer.waitForResponse(r=>r.url().endsWith('/applications')&&r.request().method()==='POST');
  await customer.getByRole('button',{name:'Buat draf pengajuan',exact:true}).click();loan=(await (await created).json()).data;
  for(const [label,value] of Object.entries({'NIK fiktif':'000'+Date.now(),'Tanggal lahir':'1995-01-15','Nomor kontak fiktif':'+62000000003','Jenis pekerjaan':'Karyawan fiktif','Tempat bekerja':'Perusahaan Fiktif','Lama bekerja (bulan)':'24','Alamat fiktif':'Alamat demo model fiktif'}))await customer.getByLabel(label,{exact:true}).fill(value);
  await customer.getByRole('button',{name:'Simpan profil',exact:true}).click();await expect(customer.getByText('Profil tersimpan. Data pengiriman sebelumnya tetap dipertahankan.')).toBeVisible();
  for(const [label,value] of Object.entries({'Jumlah pinjaman (Rp)':'20000000','Pendapatan per bulan (Rp)':'8000000','Pengeluaran di luar cicilan (Rp)':'3000000','Cicilan berjalan per bulan (Rp)':'1000000','Tenor (bulan)':'12','Tujuan pinjaman':'Demo model asli'}))await customer.getByLabel(label,{exact:true}).fill(value);
  await customer.getByRole('button',{name:'Simpan draf',exact:true}).click();await expect(customer.getByText('Draf tersimpan. Pengajuan belum dikirim untuk ditinjau.')).toBeVisible();
  fixture('documents',{application_id:loan.id});
  const panel=customer.getByRole('region',{name:'Dokumen pengajuan',exact:true});await panel.getByLabel('Jenis dokumen',{exact:true}).selectOption({label:'D01 · Identitas'});
  await panel.getByLabel('Berkas fiktif (PDF, JPG, JPEG, PNG)',{exact:true}).setInputFiles({name:'identitas-model-fiktif.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n')});
  await panel.getByRole('button',{name:'Unggah versi baru'}).click();await expect(panel.getByRole('button',{name:'Unduh D01',exact:true})).toBeEnabled();
  await customer.getByRole('link',{name:'Tinjau dan lihat pengiriman',exact:true}).click();await submit('Tinjau dan kirim');
  expect(worker(true).checks.at(-1).status).toBe('failed');
  await login(verifier,'verifier');await claim(verifier);
  await verifier.getByRole('button',{name:'Ulangi pemeriksaan',exact:true}).click();await verifier.getByRole('button',{name:'Ya, ulangi pemeriksaan',exact:true}).click();
  await expect(verifier.getByText('Percobaan 2 · Diproses',{exact:true})).toBeVisible();
  const retry=worker();expect(retry.checks.map((c:{status:string})=>c.status)).toEqual(['failed','succeeded']);expect(retry.checks[1].model).toBe('if-v1-b-seed17');
  await verifier.getByRole('button',{name:'Periksa data terbaru',exact:true}).click();
  await expect(verifier.getByText('Percobaan 2 · Selesai',{exact:true})).toBeVisible();
  await verifier.getByLabel('Catatan / alasan revisi',{exact:true}).fill('Alasan revisi model fiktif');await action(verifier,'Minta revisi','Konfirmasi tindakan');
  await customer.reload();await expect(customer.getByText('Alasan revisi: Alasan revisi model fiktif',{exact:true})).toBeVisible();
  await submit('Tinjau kirim ulang');const resubmitted=worker();expect(resubmitted.checks[0].status).toBe('succeeded');expect(resubmitted.submission).not.toBe(retry.submission);
  await claim(verifier);await verifier.getByLabel('Catatan / alasan revisi',{exact:true}).fill('Model asli sudah ditinjau manusia');
  await verifier.screenshot({path:`../docs/validation/${prefix}model-flow-internal-desktop.png`,fullPage:true});
  await action(verifier,'Teruskan ke penyetuju tingkat 1','Konfirmasi tindakan');
  await login(one,'one');await claim(one);await action(one,'Setuju dan teruskan ke tingkat 2','Konfirmasi keputusan');
  await login(two,'two');await claim(two);await action(two,'Setujui pengajuan final','Konfirmasi keputusan');
  expect(inspect().status).toBe('approved');await customer.reload();await expect(customer.getByText('Disetujui',{exact:true}).first()).toBeVisible();
  await customer.setViewportSize({width:320,height:780});expect(await customer.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await customer.screenshot({path:`../docs/validation/${prefix}model-flow-customer-mobile-320.png`,fullPage:true});
  await writeFile(`../docs/validation/${prefix}model-flow-browser-evidence.json`,JSON.stringify({application_id:loan.id,version:'if-v1-b-seed17',restricted_database:runtime,states:evidence,document_setup:'D01 UI; other required types guarded fixtures'},null,2)+'\n');
 } finally {await Promise.all(contexts.map(c=>c.close()));}
});
