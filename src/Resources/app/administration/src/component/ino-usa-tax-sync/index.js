const { Component } = Shopware;

Component.register('ino-usa-tax-sync', {
    inject: ['loginService', 'acl'],
    template: `
        <div class="ino-usa-tax-sync">
            <mt-banner variant="info">
                官方同步 / Official synchronization: California (CA): official address verification + local daily cache. Washington (WA): ZIP+4 required.
                Other states, food and special product tax rules are not covered. Existing orders retain their saved tax amounts.
            </mt-banner>
            <p>后台任务需要运行 Shopware async worker。同步失败保留旧版本；旧版本过期后将阻止下单。</p>
            <label>同步州 / State <select v-model="state"><option value="CA">California</option><option value="WA">Washington</option></select></label>
            <mt-button :disabled="loading || !acl.can('system.system_config')" :is-loading="loading" @click="synchronize">
                同步官方税率 / Synchronize official rates
            </mt-button>
            <mt-button :disabled="loading" @click="refresh">刷新状态 / Refresh status</mt-button>
            <p v-if="error" role="alert">{{ error }}</p>
            <div v-for="source in sources" :key="source.state" style="margin-top: 16px; overflow-wrap: anywhere">
                <p>{{ source.state }} · {{ source.status }} · Records: {{ source.row_count || 0 }}</p>
                <p>有效期 / Valid: {{ source.valid_from || '—' }} → {{ source.valid_until || '—' }}</p>
                <p>Last completed (UTC): {{ source.completed_at || '—' }}</p>
                <p>Version: {{ source.active_dataset_id || 'No local version' }}</p>
                <p>SHA-256: {{ source.checksum || '—' }}</p>
                <a v-if="source.source_url" :href="source.source_url" target="_blank" rel="noopener noreferrer">Official source file</a>
                <p v-if="source.last_error" role="alert">{{ source.last_error }}</p>
            </div>
        </div>`,
    data() { return { state: 'CA', sources: [], loading: false, error: '', timer: null, disposed: false }; },
    computed: { busy() { return this.sources.some(s => ['queued', 'running'].includes(s.status)); } },
    created() { this.refresh(); },
    beforeUnmount() { this.disposed = true; clearTimeout(this.timer); },
    methods: {
        async request(path, method = 'get') {
            const client = Shopware.Application.getContainer('init').httpClient;
            return client[method](`_action/inocean-usa-tax/${path}`, ...(method === 'post' ? [{}, { headers: { Authorization: `Bearer ${this.loginService.getToken()}`, Accept: 'application/json' } }] : [{ headers: { Authorization: `Bearer ${this.loginService.getToken()}`, Accept: 'application/json' } }]));
        },
        async refresh() {
            clearTimeout(this.timer);
            this.loading = true;
            try { const { data } = await this.request('status'); this.sources = data.sources; this.error = ''; }
            catch { this.error = '无法读取同步状态。请检查权限、插件迁移及服务器日志。 / Cannot read status.'; }
            finally {
                this.loading = false;
                if (!this.disposed && this.busy) { this.timer = setTimeout(() => this.refresh(), 5000); }
            }
        },
        async synchronize() {
            this.loading = true; this.error = '';
            try { await this.request(`sync?state=${this.state}`, 'post'); await this.refresh(); }
            catch { this.error = '同步未能入队。请检查后台任务及服务器日志。 / Could not queue synchronization.'; }
            finally { this.loading = false; }
        },
    },
});
