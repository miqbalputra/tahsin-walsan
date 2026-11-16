/* Compatibility form: serialize each card's requests and retain uncertain sends for retry. */
(() => {
    'use strict';
    const pending = new Set();
    const unsaved = () => !!document.querySelector('[data-legacy-unsaved="1"]');
    window.legacyAttendanceCanLeave = () => !unsaved() || window.confirm('Ada isian atau pengiriman yang belum selesai. Tinggalkan halaman dan lepaskan isian yang belum tersimpan?');
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link || event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey
            || link.target === '_blank' || link.hasAttribute('download') || (link.getAttribute('href') || '').startsWith('#')) return;
        if (!window.legacyAttendanceCanLeave()) event.preventDefault();
        else window.legacyAttendanceLeaving = true;
    });
    window.addEventListener('beforeunload', event => {
        if (!window.legacyAttendanceLeaving && unsaved()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    const operationId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
    window.legacyAttendanceFinalize = async (event, scope) => {
        if (scope.isFinalizing) return;
        scope.isFinalizing = true;
        window.attendanceFinalizing = true;
        const form = event.target;
        const error = form.querySelector('[data-legacy-error]');
        try {
            while (pending.size) await Promise.allSettled(Array.from(pending));
            if (form.querySelector('[data-legacy-blocked="1"]')) {
                error.textContent = 'Ada pengiriman yang belum pasti atau catatan berubah. Periksa pesan pada peserta sebelum finalisasi.';
                error.hidden = false;
                return;
            }
            await scope.$nextTick();
            if (form.reportValidity()) {
                window.legacyAttendanceLeaving = true;
                HTMLFormElement.prototype.submit.call(form);
            }
        } finally {
            scope.isFinalizing = false;
            window.attendanceFinalizing = false;
        }
    };
    window.legacyAttendanceCard = (config, confirm) => ({
        ...config, isSaving: false, isSaved: config.wasSaved, saveError: '', blocked: false,
        retryRequest: null, queue: Promise.resolve(), queued: 0,
        markEdited() { this.isSaved = false; this.$el.dataset.legacyUnsaved = '1'; },
        payload() {
            const value = field => this.$el.querySelector(`[name$="[${field}]"]`)?.value || '';
            return {intent: this.intent, status: this.status, jenis_materi: this.materi,
                alasan: value('alasan'), jilid: value('jilid'), nama_surat_dari: this.suratDari,
                nama_surat_sampai: this.suratSampai, halaman_dari: this.halamanDari,
                halaman_sampai: this.halamanSampai,
                hasil_talaqqi: this.$el.querySelector('[name$="[hasil_talaqqi]"]:checked')?.value || ''};
        },
        isValid(payload) {
            if (!payload.status) return false;
            if (['S', 'I'].includes(payload.status)) return !!payload.alasan.trim();
            if (payload.status !== 'H') return payload.status === 'A';
            return ['Iqro', 'Al Quran'].includes(payload.jenis_materi) && !!payload.hasil_talaqqi
                && !!payload.halaman_dari && !!payload.halaman_sampai
                && (payload.jenis_materi === 'Iqro' ? !!payload.jilid : !!payload.nama_surat_dari && !!payload.nama_surat_sampai);
        },
        setBlocked(blocked) { this.blocked = blocked; this.$el.dataset.legacyBlocked = blocked ? '1' : '0'; if (blocked) this.markEdited(); },
        saveDraft() {
            if (window.attendanceFinalizing || this.intent === 'reset' || this.blocked) return;
            const payload = this.payload();
            this.isSaved = false;
            if (!this.isValid(payload)) {
                this.saveError = 'Isian belum lengkap dan belum tersimpan. Lengkapi materi/hasil Hadir atau alasan Sakit/Izin.';
                return;
            }
            this.send({payload, operation: operationId(), expected: null});
        },
        resetSelection() {
            if (this.blocked || window.attendanceFinalizing) return;
            confirm('Batalkan Presensi?', 'Catatan sesi ini dibatalkan. Salinan versi lama tetap tersedia untuk pemulihan oleh pengelola.', () => {
                this.intent = 'reset';
                this.send({payload: {intent: 'reset'}, operation: operationId(), expected: null});
            });
        },
        retrySend() {
            if (!this.retryRequest || this.isSaving) return;
            const request = this.retryRequest;
            this.retryRequest = null;
            this.setBlocked(false);
            this.send(request);
        },
        send(request) {
            this.markEdited();
            this.queued++;
            this.isSaving = true;
            this.isSaved = false;
            const task = this.queue.then(async () => {
                if (this.blocked) return;
                const form = this.$el.closest('form');
                const version = this.$el.querySelector('[data-entry-revision]');
                if (request.expected === null) request.expected = version.value;
                const body = new FormData();
                const values = {...request.payload, csrf_token: form.elements.csrf_token.value,
                    attendance_form_token: form.elements.attendance_form_token.value,
                    halaqoh_id: config.groupId, wali_santri_id: config.waliId, tanggal: config.date,
                    entry_revision: request.expected, operation_id: request.operation};
                for (const [key, value] of Object.entries(values)) body.append(key, value);
                this.saveError = '';
                try {
                    const response = await fetch('api-save-single.php', {method: 'POST', body});
                    const result = await response.json();
                    if (!response.ok || result.status !== 'success') {
                        this.saveError = result.message || 'Catatan belum tersimpan.';
                        if (response.status >= 500) throw new Error('uncertain');
                        if ([401, 403, 409].includes(response.status)) this.setBlocked(true);
                        return;
                    }
                    const revision = result.entry_revisions?.[String(config.waliId)];
                    if (!/^[a-f0-9]{64}$/.test(revision || '')) throw new Error('uncertain');
                    version.value = revision;
                    this.retryRequest = null;
                    if (request.payload.intent === 'reset' && this.intent === 'reset') this.status = '';
                    const current = request.payload.intent === 'reset' && this.intent === 'reset'
                        ? {intent: 'reset'} : this.payload();
                    const unchanged = JSON.stringify(current) === JSON.stringify(request.payload);
                    this.$el.dataset.legacyUnsaved = unchanged ? '0' : '1';
                    this.isSaved = unchanged;
                    if (!unchanged) this.saveError = 'Ada perubahan lain yang belum tersimpan. Lengkapi isian lalu finalisasi.';
                } catch (_) {
                    this.retryRequest = request;
                    this.setBlocked(true);
                    this.saveError = 'Hasil pengiriman belum pasti. Isian tetap tersedia. Ulangi pengiriman yang sama atau periksa formulir terbaru.';
                }
            }).catch(() => {
                this.setBlocked(true);
                this.saveError = 'Pengiriman terhenti. Isian tetap tersedia; periksa formulir terbaru.';
            }).finally(() => {
                this.queued--;
                this.isSaving = this.queued > 0;
                pending.delete(task);
            });
            this.queue = task;
            pending.add(task);
            return task;
        }
    });
})();
