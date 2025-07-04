@extends('layouts.main')

@section('content')
    <div class="w-100 grid-margin stretch-card">
        <div class="card">
            <div class="card-body w-auto">
                <div class="row mb-4 align-items-center">
                <div class="col-12 col-md-6 mb-3 mb-md-0">
                    <h3 class="card-title">Data Laporan</h3>
                </div>
                <div class="col-12 col-md-6 text-md-right">
                    @if ($authUser->peran->kode_peran == 'ADM')
                    <a href="{{ url('laporan/create') }}"
                        class="btn btn-primary btn-block btn-md-auto">
                        Tambah Laporan
                    </a>
                    @endif
                </div>
                </div>
                <div class="table-responsive">
                    @if (session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif
                    <table class="table table-hover table-striped" id="table-Laporan">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Pelapor</th>
                                <th>Gedung</th>
                                <th>Lantai</th>
                                <th>Ruangan</th>
                                <th>Tanggal Laporan</th>
                                <th>Jumlah Vote</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="myModal" class="modal fade animate shake" tabindex="-1" role="dialog" data-backdrop="static"
        data-keyboard="false" data-width="75%" aria-hidden="true">
    </div>
@endsection

@push('css')

@endpush

@push('js')
    <script>
        function modalAction(url = '') {
            $('#myModal').load(url, function() {
                $('#myModal').modal('show');
            });
        }

        var tableLaporan;
        $(document).ready(function() {
            tableLaporan = $('#table-Laporan').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    "url": "{{ url('laporan/list') }}",
                    "dataType": "json",
                    "type": "GET"
                },
                columns: [{
                        data: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'pengguna.nama',
                    },
                    {
                        data: 'gedung.nama_gedung',
                    },
                    {
                        data: 'lantai.nomor_lantai',
                    },
                    {
                        data: 'ruangan.nama_ruangan',
                    },
                    {
                        data: 'created_at',
                    },
                    { data: 'jumlah_vote', name: 'jumlah_vote' },
                    {
                        data: 'aksi',
                        orderable: false,
                        searchable: false
                    },
                ]
            })
        })
    </script>
@endpush
