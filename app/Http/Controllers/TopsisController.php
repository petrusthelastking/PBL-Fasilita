<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Status;
use App\Models\Kriteria;
use App\Models\SkorTipe;
use App\Models\SkorTopsis;
use Illuminate\Http\Request;
use App\Models\LaporanFasilitas;
use Illuminate\Support\Facades\DB;
use App\Models\SkorKriteriaLaporan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Yajra\DataTables\Facades\DataTables;

class TopsisController extends Controller
{
     public function index(Request $request)
    {
        // selalu ambil kriteria
        $breadcrumbs = [
            ['title'=>'Dashboard','url'=>route('dashboard')],
            ['title'=>'Analisis TOPSIS','url'=>route('spk.index')],
        ];

        // cek apakah ada runId, dan apakah ada step cache-nya
        $runId = $request->input('runId')
            ?: optional(SkorTipe::where('tipe','global')
                        ->latest('created_at')
                        ->first())
                        ->id_skor_tipe;

        $cacheKey = "topsis.steps.{$runId}";

        // Jika ada cache, gunakan cache
        if (Cache::has($cacheKey)) {
            $steps = Cache::get($cacheKey);
            return view('perhitungan.index', [
                'breadcrumbs' => $breadcrumbs,
                'kriterias'   => $steps['kriterias'],
                'alternatifs' => $steps['alternatifs'],
                'norm'        => $steps['norm'],
                'V'           => $steps['V'],
                'idealPos'    => $steps['idealPos'],
                'idealNeg'    => $steps['idealNeg'],
                'distPos'     => $steps['distPos'],
                'distNeg'     => $steps['distNeg'],
                'Ci'          => $steps['Ci'],
                'runId'       => $steps['runId'],
            ]);
        }

        // Jika tidak ada cache tapi ada data SkorTipe, rebuild cache dari database
        if ($runId && $this->rebuildCacheFromDatabase($runId)) {
            $steps = Cache::get($cacheKey);
            return view('perhitungan.index', [
                'breadcrumbs' => $breadcrumbs,
                'kriterias'   => $steps['kriterias'],
                'alternatifs' => $steps['alternatifs'],
                'norm'        => $steps['norm'],
                'V'           => $steps['V'],
                'idealPos'    => $steps['idealPos'],
                'idealNeg'    => $steps['idealNeg'],
                'distPos'     => $steps['distPos'],
                'distNeg'     => $steps['distNeg'],
                'Ci'          => $steps['Ci'],
                'runId'       => $steps['runId'],
            ]);
        }

        // mode "belum hitung" — ambil fresh data (unassigned) saja
        $kriterias   = Kriteria::orderBy('kode_kriteria')->get();
        $alternatifs = LaporanFasilitas::with('penilaian.skorKriteriaLaporan.kriteria')
            ->where('id_status', Status::VALID)
            ->where('is_active', true)
            ->whereDoesntHave('penugasan')
            ->whereHas('penilaian')
            ->get();

        return view('perhitungan.index', [
            'breadcrumbs' => $breadcrumbs,
            'kriterias'   => $kriterias,
            'alternatifs' => $alternatifs,
            'runId'       => $runId,
        ]);
    }

    /**
     * Rebuild cache dari data yang ada di database
     */
    private function rebuildCacheFromDatabase($runId)
    {
        try {
            // Cek apakah SkorTipe ada
            $skorTipe = SkorTipe::find($runId);
            if (!$skorTipe) {
                return false;
            }

            // Ambil data SkorTopsis
            $skorTopsis = SkorTopsis::where('id_skor_tipe', $runId)
                ->with('laporanFasilitas.fasilitas', 'laporanFasilitas.laporan.pengguna')
                ->get();

            if ($skorTopsis->isEmpty()) {
                return false;
            }

            // Rebuild langkah-langkah perhitungan
            $kriterias = Kriteria::orderBy('kode_kriteria')->get();
            $alternatifs = $skorTopsis->map(function($st) {
                return $st->laporanFasilitas;
            });

            // Ambil data mentah untuk rebuild matriks
            $data = [];
            foreach($alternatifs as $alt){
                $row = ['id'=>$alt->id_laporan_fasilitas];
                foreach($kriterias as $k){
                    $sk = $alt->penilaian->first()
                        ?->skorKriteriaLaporan
                        ->firstWhere('id_kriteria',$k->id_kriteria);
                    $row[$k->kode_kriteria] = $sk->nilai_mentah ?? 0;
                }
                $data[] = $row;
            }

            // Rebuild normalisasi
             $norm = [];
        foreach($kriterias as $k){
            $sumSq = array_sum(array_map(fn($r)=> pow($r[$k->kode_kriteria],2), $data));
            $den   = sqrt($sumSq) ?: 1;
            foreach($data as $i=>$r){
                $norm[$i][$k->kode_kriteria] = $r[$k->kode_kriteria]/$den;
            }
        }

        // 7) Matriks terbobot V
        $V = [];
        foreach($kriterias as $k){
            foreach($norm as $i=>$r){
                $V[$i][$k->kode_kriteria] = $r[$k->kode_kriteria] * $k->bobot_kriteria;
            }
        }

        // 8) Ideal positif & negatif
        $idealPos = []; $idealNeg = [];
        foreach($kriterias as $k){
            $col = array_column($V, $k->kode_kriteria);
            if ($k->tipe_kriteria==='benefit') {
                $idealPos[$k->kode_kriteria] = max($col);
                $idealNeg[$k->kode_kriteria] = min($col);
            } else {
                $idealPos[$k->kode_kriteria] = min($col);
                $idealNeg[$k->kode_kriteria] = max($col);
            }
        }

        // 9) Hitung jarak & Ci
        $distPos = []; $distNeg = []; $CiMap = [];
        foreach($V as $i=>$r){
            $d1=$d2=0;
            foreach($kriterias as $k){
                $d1 += pow($r[$k->kode_kriteria]-$idealPos[$k->kode_kriteria],2);
                $d2 += pow($r[$k->kode_kriteria]-$idealNeg[$k->kode_kriteria],2);
            }
            $distPos[$i]=sqrt($d1);
            $distNeg[$i]=sqrt($d2);
            $CiMap[$data[$i]['id']] =
                ($distPos[$i]+$distNeg[$i])>0
                ? $distNeg[$i]/($distPos[$i]+$distNeg[$i])
                : 0;
        }

            // Cache ulang
            $cacheKey = "topsis.steps.{$runId}";
            Cache::put($cacheKey, [
                'kriterias'=>$kriterias,
                'alternatifs'=>$alternatifs,
                'norm'=>$norm,
                'V'=>$V,
                'idealPos'=>$idealPos,
                'idealNeg'=>$idealNeg,
                'distPos'=>$distPos,
                'distNeg'=>$distNeg,
                'Ci'=>$CiMap,
                'runId'=>$runId,
            ], now()->addDays(3));

            return true;

        } catch (\Exception $e) {
            Log::error('Error rebuilding cache: ' . $e->getMessage());
            return false;
        }
    }

    public function listAlternatif(Request $request)
    {
        // 1) grab all kriteria once
        $kriterias = Kriteria::orderBy('kode_kriteria')->get();

        // 2) load all laporan fasilitas with their penilaian
        $items = LaporanFasilitas::with([
            'fasilitas',
            'laporan.pengguna',
            'penilaian.skorKriteriaLaporan'
        ])
        ->where('id_status', Status::VALID)
        ->where('is_active', true)
        ->whereHas('penilaian')
        ->get();

        // 3) map into a flat array per row
        $rows = $items->map(function($lf) use($kriterias) {
            $base = [
                'id'          => $lf->id_laporan_fasilitas,
                'alternatif'  => $lf->fasilitas->nama_fasilitas,
                'pelapor'     => $lf->laporan->pengguna->nama,
            ];

            // for each kriteria, attach its kode_kriteria => nilai_mentah (or '-')
            foreach($kriterias as $k) {
                $sk = $lf->penilaian
                         ->first()?->skorKriteriaLaporan
                         ->firstWhere('id_kriteria', $k->id_kriteria);
                $base[$k->kode_kriteria] = $sk->nilai_mentah ?? '-';
            }

            // add the edit-URL for the action column
            $base['aksi'] = '<button class="btn btn-sm btn-warning btn-edit" data-url="'
                .route('spk.edit', $lf->id_laporan_fasilitas).
                '"><i class="mdi mdi-pencil"></i></button>';

            return $base;
        });

        // 4) feed the collection directly into DataTables
        return DataTables::of($rows)
            ->addIndexColumn()
            ->rawColumns(['aksi'])
            ->make(true);
    }

    public function hitung(Request $request)
    {
        try {
            // 1) Ambil count alternatif & kriteria sekarang
            $curAlt = LaporanFasilitas::where('id_status',Status::VALID)
                        ->where('is_active',true)
                        ->whereDoesntHave('penugasan')
                        ->count();
            $curCri = Kriteria::count();

            // 2) Cek run global terakhir
            $last = SkorTipe::where('tipe','global')->latest('created_at')->first();

            // 2a) Cek perubahan pada nilai kriteria (skor mentah)
            $lastScoreUpdateRaw = SkorKriteriaLaporan::max('updated_at');
            $lastScoreUpdate = $lastScoreUpdateRaw ? Carbon::parse($lastScoreUpdateRaw) : null;

            // 2b) Jika belum 3 hari, jumlah alt/cri sama, dan skor tidak berubah sejak run terakhir → skip
            if ($last
                && $last->created_at->gt(now()->subDays(3))
                && $last->alt_count === $curAlt
                && $last->cri_count === $curCri
                && (! $lastScoreUpdate || $lastScoreUpdate->lte($last->created_at))
            ) {
                return redirect()
                    ->route('spk.index',['runId'=>$last->id_skor_tipe])
                    ->with('info','Tidak ada perubahan data atau nilai sejak perhitungan terakhir. Skip.');
            }

            // 3) Ambil pool alternatif & validasi minimal satu
            $alts = LaporanFasilitas::with('penilaian.skorKriteriaLaporan.kriteria')
                ->where('id_status',Status::VALID)
                ->where('is_active',true)
                ->whereDoesntHave('penugasan')
                ->whereHas('penilaian')
                ->get();

            if ($alts->isEmpty()) {
                return redirect()
                    ->route('spk.index')
                    ->with('error','Tidak ada alternatif valid untuk dihitung.');
            }

            // 4) Buat run baru, simpan counts
            $run = SkorTipe::create([
                'tipe'=>'global',
                'alt_count'=>$curAlt,
                'cri_count'=>$curCri,
            ]);

            // 5) Persiapkan data matriks
             // 5) Persiapkan data matriks
        $kriterias = Kriteria::orderBy('kode_kriteria')->get();
        $data = [];
        foreach($alts as $alt){
            $row = ['id'=>$alt->id_laporan_fasilitas];
            foreach($kriterias as $k){
                $sk = $alt->penilaian->first()
                    ?->skorKriteriaLaporan
                    ->firstWhere('id_kriteria',$k->id_kriteria);
                $row[$k->kode_kriteria] = $sk->nilai_mentah ?? 0;
            }
            $data[] = $row;
        }

        // 6) Normalisasi R
        $norm = [];
        foreach($kriterias as $k){
            $sumSq = array_sum(array_map(fn($r)=> pow($r[$k->kode_kriteria],2), $data));
            $den   = sqrt($sumSq) ?: 1;
            foreach($data as $i=>$r){
                $norm[$i][$k->kode_kriteria] = $r[$k->kode_kriteria]/$den;
            }
        }

        // 7) Matriks terbobot V
        $V = [];
        foreach($kriterias as $k){
            foreach($norm as $i=>$r){
                $V[$i][$k->kode_kriteria] = $r[$k->kode_kriteria] * $k->bobot_kriteria;
            }
        }

        // 8) Ideal positif & negatif
        $idealPos = []; $idealNeg = [];
        foreach($kriterias as $k){
            $col = array_column($V, $k->kode_kriteria);
            if ($k->tipe_kriteria==='benefit') {
                $idealPos[$k->kode_kriteria] = max($col);
                $idealNeg[$k->kode_kriteria] = min($col);
            } else {
                $idealPos[$k->kode_kriteria] = min($col);
                $idealNeg[$k->kode_kriteria] = max($col);
            }
        }

        // 9) Hitung jarak & Ci
        $distPos = []; $distNeg = []; $CiMap = [];
        foreach($V as $i=>$r){
            $d1=$d2=0;
            foreach($kriterias as $k){
                $d1 += pow($r[$k->kode_kriteria]-$idealPos[$k->kode_kriteria],2);
                $d2 += pow($r[$k->kode_kriteria]-$idealNeg[$k->kode_kriteria],2);
            }
            $distPos[$i]=sqrt($d1);
            $distNeg[$i]=sqrt($d2);
            $CiMap[$data[$i]['id']] =
                ($distPos[$i]+$distNeg[$i])>0
                ? $distNeg[$i]/($distPos[$i]+$distNeg[$i])
                : 0;
        }

        // 10) Simpan Ci ke DB
        DB::transaction(fn() =>
            collect($alts)->each(fn($alt,$i) =>
                SkorTopsis::updateOrCreate(
                    ['id_skor_tipe'=>$run->id_skor_tipe,'id_laporan_fasilitas'=>$alt->id_laporan_fasilitas],
                    ['skor'=>$CiMap[$alt->id_laporan_fasilitas]]
                )
            )
        );


            // 11) Cache langkah selengkapnya selama 3 hari
            Cache::put("topsis.steps.{$run->id_skor_tipe}", [
                'kriterias'=>$kriterias,
                'alternatifs'=>$alts,
                'norm'=>$norm,
                'V'=>$V,
                'idealPos'=>$idealPos,
                'idealNeg'=>$idealNeg,
                'distPos'=>$distPos,
                'distNeg'=>$distNeg,
                'Ci'=>$CiMap,
                'runId'=>$run->id_skor_tipe,
            ], now()->addDays(3));

            return redirect()
                ->route('spk.index',['runId'=>$run->id_skor_tipe])
                ->with('success','Perhitungan TOPSIS selesai.');

        } catch (\Exception $e) {
            Log::error('Error in hitung TOPSIS: ' . $e->getMessage());
            return redirect()
                ->route('spk.index')
                ->with('error', 'Terjadi kesalahan saat menghitung TOPSIS: ' . $e->getMessage());
        }
    }

    public function getCalculationResults($runId = null)
    {
        try {
            if (!$runId) {
                $last = SkorTipe::where('tipe', 'global')->latest('created_at')->first();
                $runId = $last ? $last->id_skor_tipe : null;
            }

            if (!$runId) {
                return response()->json(['success' => false, 'message' => 'Tidak ada perhitungan yang tersedia']);
            }

            $cacheKey = "topsis.steps.{$runId}";

            // Jika tidak ada cache, coba rebuild dari database
            if (!Cache::has($cacheKey)) {
                if (!$this->rebuildCacheFromDatabase($runId)) {
                    return response()->json(['success' => false, 'message' => 'Data perhitungan tidak ditemukan']);
                }
            }

            $steps = Cache::get($cacheKey);

            // Render partial view untuk hasil perhitungan
            $calculationStepsHtml = view('perhitungan.partials.calculation-steps', $steps)->render();
            $finalResultsHtml = view('perhitungan.partials.final-results', $steps)->render();

            return response()->json([
                'success' => true,
                'runId' => $runId,
                'calculationStepsHtml' => $calculationStepsHtml,
                'finalResultsHtml' => $finalResultsHtml,
                'hasResults' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting calculation results: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil hasil perhitungan']);
        }
    }

    public function checkCalculationStatus()
    {
        try {
            $last = SkorTipe::where('tipe', 'global')->latest('created_at')->first();

            return response()->json([
                'id' => $last ? $last->id_skor_tipe : null,
                'timestamp' => $last ? $last->created_at->timestamp : null,
                'formatted_time' => $last ? $last->created_at->format('d/m/Y H:i:s') : null
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking calculation status: ' . $e->getMessage());
            return response()->json([
                'id' => null,
                'timestamp' => null,
                'formatted_time' => null
            ]);
        }
    }


    public function edit($id)
    {
        $laporan = LaporanFasilitas::with(['fasilitas','laporan.pengguna','penilaian.skorKriteriaLaporan'])
                    ->findOrFail($id);
        $kriterias = Kriteria::with('skoringKriterias')->orderBy('kode_kriteria')->get();

        return view('perhitungan.edit', compact('laporan','kriterias'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nilai' => 'required|array'
        ]);

        $laporan = LaporanFasilitas::with('penilaian')->findOrFail($id);
        $penilaian = $laporan->penilaian()->firstOrCreate([ 'id_laporan_fasilitas' => $id ], [ 'id_sarpras' => auth()->id() ]);

        foreach($request->nilai as $id_kriteria => $val) {
            $penilaian->skorKriteriaLaporan()->updateOrCreate(
                ['id_kriteria' => $id_kriteria],
                ['nilai_mentah' => $val]
            );
        }

        return response()->json(['success' => true]);
    }

}
