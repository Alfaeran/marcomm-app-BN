<?php
// components/modals_user.php
?>
<div id="request-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden z-[100] animate-in fade-in duration-300">
    <div class="glass-card w-full max-w-lg overflow-hidden transform transition-all shadow-2xl scale-95 opacity-0 duration-300" id="modal-container">
        <!-- Modal Header -->
        <div class="px-8 py-6 border-b border-white/20 flex justify-between items-center bg-white/30">
            <div>
                <h3 class="text-xl font-extrabold text-slate-800 tracking-tight" id="modal-title">Request Hapus</h3>
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Konfirmasi Penghapusan</p>
            </div>
            <button onclick="closeModal()" class="h-10 w-10 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-white/50 rounded-xl transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form id="eventRequestForm" action="process/user_event_request_process.php" method="POST" class="p-8">
            <input type="hidden" name="event_id" id="modal_event_id">
            <input type="hidden" name="request_type" id="modal_request_type">
            
            <div class="mb-6 p-4 bg-blue-50/50 rounded-2xl border border-blue-100/50">
                <div class="flex gap-3">
                    <div class="h-8 w-8 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center shrink-0">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-blue-800">Event Target:</p>
                        <p id="modal_event_name" class="text-sm font-black text-slate-800"></p>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div>
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 px-1">Alasan Request <span class="text-red-500">*</span></label>
                    <textarea name="reason" rows="3" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl p-4 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400" placeholder="Jelaskan secara singkat alasan Anda..."></textarea>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="flex items-center gap-3 mt-10">
                <button type="button" onclick="closeModal()" class="flex-grow py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all">
                    Batal
                </button>
                <button type="submit" class="flex-grow py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                    Kirim Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('request-modal');
    const container = document.getElementById('modal-container');

    function openModal(btn) {
        document.getElementById('modal_event_id').value = btn.dataset.eventId;
        document.getElementById('modal_event_name').textContent = btn.dataset.eventName;
        const type = btn.dataset.requestType;
        document.getElementById('modal_request_type').value = type;
        

        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeModal() {
        container.classList.add('scale-95', 'opacity-0');
        container.classList.remove('scale-100', 'opacity-100');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.getElementById('eventRequestForm').reset();
        }, 300);
    }


    document.querySelectorAll('.request-btn').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn));
    });

    // Close on overlay click
    modal.addEventListener('click', (e) => {
        if(e.target === modal) closeModal();
    });
</script>
