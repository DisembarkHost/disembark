<div id="app" v-cloak>
<v-app>
    <div style="position: absolute; top: 10px; right: 10px; z-index: 10; display: flex; gap: 4px;">
        <v-menu location="bottom end" :close-on-content-click="false">
             <template v-slot:activator="{ props }">
                 <v-btn icon variant="text" v-bind="props">
                     <v-icon>mdi-tools</v-icon>
                     <v-tooltip location="left" activator="parent" text="Cleanup & Settings"></v-tooltip>
                 </v-btn>
             </template>
             <v-card max-width="650" class="pa-3">
                <v-card-text class="text-body-2">
                    <p class="mb-2">In order to generate a zip file locally you'll need to have Disembark CLI.</p>
                    <div style="position: relative;margin-bottom: 14px;padding-right: 42px;background: #2d2d2d; border-radius: 4px;">
                        <pre style="font-size: 11px;color: #f8f8f2;background: #2d2d2d;padding: 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ cliInstall }}</pre>
                        <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliInstall )" style="color: #f8f8f2;caret-color: #f8f8f2;position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
                    </div>
                    <v-divider class="my-3"></v-divider>
                    <div v-if="backup_disk_size > 0">
                        <p class="mb-2 text-body-2">You can free up <strong>{{ formatSize(backup_disk_size) }}</strong> of disk space by removing temporary backup files.</p>
                        <v-btn
                            block
                            color="warning"
                            @click="cleanupBackups"
                            :loading="cleaning_up"
                        >
                            Cleanup Temporary Files
                        </v-btn>
                    </div>
                    <div v-else>
                        <p class="text-body-2">No temporary backup files to clean up.</p>
                    </div>
                    <v-divider class="my-3"></v-divider>
                    <div>
                        <p class="mb-2 text-body-2">Regenerate the site token. This will invalidate any existing CLI connections or commands.</p>
                        <v-btn
                            block
                            color="error"
                            @click="regenerateToken"
                            :loading="regenerating_token"
                        >
                            Regenerate Token
                            <v-icon end>mdi-refresh</v-icon>
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-menu>
        <v-btn icon variant="text" @click="toggleTheme">
            <v-icon>{{ isDarkMode ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
            <v-tooltip location="left" activator="parent" text="Toggle Theme"></v-tooltip>
        </v-btn>
    </div>
    <v-main>
    <v-container>
    <div style="position: relative;" id="disembark-app-container">
    <v-card v-if="ui_state === 'initial' || ui_state === 'connected'" style="max-width: 600px; margin: 0px auto 20px auto;position:relative" class="pa-3">
        <v-card-text>
        <v-row v-if="ui_state === 'initial'">
            <v-col cols="12">
                <v-btn block color="primary" @click="handleMainAction">
                    Analyze Site & Prepare Backup
                    <v-icon class="ml-2">mdi-magnify-scan</v-icon>
                </v-btn>
            </v-col>
        </v-row>
        <v-row v-if="ui_state === 'connected'">
            <v-col cols="12" sm="6">
                <v-btn block color="primary" @click="showExplorer">
                    <v-icon left>mdi-folder-search-outline</v-icon>
                    Explore Files
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6">
                <v-btn block color="secondary" @click="startBackupUI">
                    <v-icon left>mdi-cloud-download</v-icon>
                    Start Backup
                </v-btn>
            </v-col>
        </v-row>
    </v-card-text>
    </v-card>
    <v-overlay v-model="loading" contained persistent attach="#app" opacity="0.7" class="align-center justify-center">
        <div class="text-center text-white text-body-1 disembark-overlay-content" style="width: 500px; max-width: 90vw;">
            <div class="mb-5"><strong>Backup in progress...</strong></div>

            <div v-if="included_tables.length > 0 && this.options.include_database" class="mb-4">
                <div class="text-left text-body-2 mb-1">Database</div>
                <v-progress-linear v-model="databaseProgress" color="amber" height="25">
                    Copied {{ database_progress.copied }} of {{ database_backup_queue.length }} items
                </v-progress-linear>
            </div>

            <div v-if="exclusionReport.remainingFiles > 0">
                <div class="text-left text-body-2 mb-1">Files</div>
                <v-progress-linear v-model="filesProgress" color="amber" height="25">
                    Copied {{ formatLargeNumbers( files_progress.copied ) }} of {{ formatLargeNumbers ( exclusionReport.remainingFiles ) }}
                </v-progress-linear>
            </div>

            <div class="mt-5 text-caption">Refreshing this page will cancel the current backup.</div>
        </div>
    </v-overlay>
    <v-overlay v-model="analyzing" contained persistent attach="#app" opacity="0.7" class="align-center justify-center">
        <div class="text-center text-white text-body-1 disembark-overlay-content">
            <div v-if="scan_progress.status === 'scanning'">
                <div><strong>Scanning file structure...</strong></div>
                <v-progress-circular indeterminate color="white" class="my-5" :size="32" :width="2"></v-progress-circular>
                <div>({{ scan_progress.scanned }} of {{ scan_progress.total }} directories scanned)</div>
            </div>
            <div v-else-if="scan_progress.status === 'chunking'">
                <div><strong>Generating file list...</strong></div>
                <v-progress-circular indeterminate color="white" class="my-5" :size="32" :width="2"></v-progress-circular>
                <div v-if="manifest_progress.total > 0">({{ manifest_progress.fetched }} of {{ manifest_progress.total }} parts created)</div>
            </div>
            <div v-else>
                <div><strong>Analyzing file structure...</strong></div>
                <v-progress-circular indeterminate color="white" class="my-5" :size="32" :width="2"></v-progress-circular>
                <div v-if="manifest_progress.total > 1">({{ manifest_progress.fetched }} of {{ manifest_progress.total }} parts loaded)</div>
            </div>
            <div v-if="files_total > 0">({{ formatLargeNumbers(files_total) }} files found)</div>
        </div>
    </v-overlay>
    <div style="opacity:0;"><textarea id="clipboard" style="height:1px;width:10px;display:flex;cursor:default"></textarea></div>
    <v-alert variant="outlined" type="success" text v-if="migrateCommand" class="mb-4">
        Backup is ready. You can generate a zip file locally use the following commands in your terminal.
        <div style="position: relative;margin-top: 14px;padding-right: 42px;background: #2d2d2d;">
        <pre style="font-size: 11px;color: #f8f8f2;background: #2d2d2d;padding: 14px 50px 14px 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ migrateCommand }}</pre>
        <v-btn variant="text" icon="mdi-content-copy" @click="copyText( migrateCommand )" style="color: #f8f8f2;caret-color: #f8f8f2;position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
      </div>
    </v-alert>
    <v-row v-if="ui_state === 'backing_up' || ui_state === 'connected'">
        <v-col cols="12" sm="12" md="6" v-if="database.length > 0">
            <v-toolbar flat dark density="compact" color="primary" class="text-white pr-5">
                <div class="ml-5 pr-3" style="font-size:18px">Database</div>
                <v-tooltip location="bottom">
                    <template v-slot:activator="{ props }">
                        <div v-bind="props" class="ml-2"> 
                            <v-switch
                                v-model="options.include_database"
                                density="compact"
                                hide-details
                                color="success"
                            ></v-switch>
                        </div>
                    </template>
                    <span>Backup Database</span>
                </v-tooltip>
                <v-spacer></v-spacer> 
                <v-btn
                    variant="text"
                    class="mr-2"
                    @click="toggleDatabaseSort"
                >
                    <v-icon :icon="database_sort_key === 'table' ? 'mdi-sort-alphabetical-variant' : 'mdi-sort-numeric-variant'"></v-icon>
                    <v-tooltip location="bottom" activator="parent">
                        Sort by {{ database_sort_key === 'table' ? 'Size' : 'Name' }}
                    </v-tooltip>
                </v-btn>
                {{ formatSize(totalDatabaseSize) }}
            </v-toolbar>
            <v-toolbar v-show="options.include_database" density="compact" flat color="white" class="px-4">
                <v-text-field
                    v-model="database_search"
                    label="Search Tables"
                    variant="underlined"
                    density="compact"
                    hide-details
                    clearable
                    flat
                    class="w-100"
                ></v-text-field>
            </v-toolbar>
            <v-list v-show="options.include_database" density="compact" style="max-height: 436px; overflow-y: auto;" class="no-select">
                <v-hover v-for="item in filteredDatabase" v-slot="{ isHovering, props }">
                    <v-list-item
                        v-bind="props"
                        :key="item.table"
                        @click="handleDbItemClick(item, $event)"
                        style="cursor: pointer;"
                        :class="{ 'text-medium-emphasis': !isTableIncluded(item) }"
                    >
                        <template v-slot:prepend>
                            <v-progress-circular
                                v-if="item.running"
                                indeterminate
                                color="primary"
                                class="mr-2"
                                :size="20"
                                :width="2"
                            ></v-progress-circular>

                            <v-icon
                                v-else-if="item.done"
                                color="success"
                                class="mr-2"
                            >
                                mdi-check-circle
                            </v-icon>

                            <v-btn
                                v-else
                                :icon="isTableIncluded(item) ? 'mdi-check-circle-outline' : 'mdi-close-circle-outline'"
                                variant="text"
                                size="x-small"
                                @click.stop="toggleTableExclusion(item)"
                                class="mr-1"
                                :color="isTableIncluded(item) ? 'success' : 'grey'"
                                :style="{ visibility: isHovering || !isTableIncluded(item) ? 'visible' : 'hidden' }"
                            ></v-btn>
                        </template>
                        <v-list-item-title class="text-truncate" :style="{ 'text-decoration': isTableIncluded(item) ? 'none' : 'line-through' }">
                            {{ item.table }} <span v-if="item.parts">({{ item.current }}/{{ item.parts }})</span>
                        </v-list-item-title>
                        <template v-slot:append>
                            <div class="text-right" style="white-space: nowrap;">
                                {{ formatSize( item.size ) }}
                            </div>
                        </template>
                    </v-list-item>
                </v-hover>
            </v-list>
        </v-col>
        <v-col cols="12" sm="12" md="6" v-if="files.length > 0">
            <v-toolbar flat dark density="compact" color="primary" class="text-white pr-5">
                <div class="mx-5" style="font-size:18px">Files</div>
                <v-tooltip location="bottom">
                    <template v-slot:activator="{ props }">
                        <div v-bind="props" class="mr-2">
                             <v-switch
                                v-model="options.include_files"
                                density="compact"
                                hide-details
                                color="success"
                                class="mr-3"
                            ></v-switch>
                        </div>
                    </template>
                    <span>Backup Files</span>
                </v-tooltip>
                <v-spacer></v-spacer>
                {{ formatSize( exclusionReport.remainingSize ) }}
            </v-toolbar>
            <v-card flat rounded="0" v-show="options.include_files">
            <v-card v-if="!tree_loading" variant="tonal" class="mx-2 mt-2 pa-2 text-caption">
                <div><b>To select a range:</b></div>
                1. Click a start file/folder.<br>
                2. Hold down the <b>Shift key</b> and click an end file/folder.
            </v-card>
            </v-card>
            <div v-if="tree_loading" class="text-center pa-5">
                <v-progress-circular indeterminate color="primary" class="my-5"></v-progress-circular>
                <div>Analyzing file structure...</div>
            </div>
            <v-treeview
                v-if="!tree_loading"
                :items="explorer.items"
                :load-children="handleLoadChildren"
                item-title="name"
                item-value="id"
                density="compact"
                style="max-height: 400px; overflow-y: auto;"
                class="no-select"
                v-show="options.include_files"
            >
                <template v-slot:title="{ item }">
                    <v-hover v-slot="{ isHovering, props }">
                        <div v-bind="props" @click="handleItemClick(item, $event)" class="d-flex align-center w-100" style="cursor: pointer;">
                            <span :class="{ 'text-medium-emphasis': isNodeExcluded(item), 'text-decoration-line-through': isNodeExcluded(item) }">
                                {{ item.name }}
                            </span>
                            <v-btn
                                :icon="isNodeExcluded(item) ? 'mdi-close-circle-outline' : 'mdi-check-circle-outline'"
                                variant="text"
                                size="x-small"
                                class="ml-auto"
                                :color="isNodeExcluded(item) ? 'grey' : 'success'"
                                @click.stop="toggleFileExclusion(item)"
                                v-show="isHovering || isNodeExcluded(item)"
                            ></v-btn>
                        </div>
                    </v-hover>
                </template>
                <template v-slot:append="{ item }">
                    <div class="text-grey text-caption">
                        {{ formatSize(item.size) }}
                    </div>
                </template>
            </v-treeview>
        </v-col>
    </v-row>
    <v-card class="mt-6" flat rounded="0" density="compact">
        <v-toolbar flat density="compact" class="text-body-2" color="primary"><v-icon icon="mdi-console" class="mr-2 ml-4"></v-icon>For limited space, you can run the backup over CLI. Use the commands below after configuring your exclusions.</v-toolbar>
        <v-card-text>
        <div style="position: relative;padding-right: 42px; border-radius: 4px;">
            <p><code @click="copyText( cliCommands.connect )" style="cursor: pointer;">{{ cliCommands.connect }}</code></p>
            <p><code @click="copyText( cliCommands.backup )" style="cursor: pointer;">{{ cliCommands.backup }}</code></p>
            <p><code @click="copyText( cliCommands.sync )" style="cursor: pointer;">{{ cliCommands.sync }}</code></p>
        </div>
        <div v-if="backup_token" class="px-2 py-2 text-caption d-flex align-center flex-wrap" style="gap: 4px;">
            <span>
                Your current Backup Session ID is: <code class="text-caption" @click="copyText( backup_token )" style="cursor: pointer;">{{ backup_token }}</code>. You can use this ID with the CLI to reuse the generated file list <code class="text-caption" @click="copyText( '--session-id=' + backup_token )" style="cursor: pointer;">--session-id={{ backup_token }}</code>.
            </span>
            <v-btn
                v-if="!manifest_is_synced"
                color="primary"
                variant="text"
                size="small"
                @click="regenerateSessionManifest"
                :loading="regenerating_manifest"
                class="pa-1"
                style="min-width: 0;"
                icon
            >
                <v-icon>mdi-refresh</v-icon>
                <v-tooltip location="top" activator="parent">Regenerate session with current file exclusions</v-tooltip>
            </v-btn>
        </div>
        </v-card-text>
    </v-card>
    </div>  
    </v-container>
    <v-dialog v-model="explorer.show" fullscreen :scrim="false" transition="none">
        <v-card>
            <v-toolbar dark color="primary">
                <v-btn icon dark @click="explorer.show = false">
                     <v-icon>mdi-close</v-icon>
                </v-btn>
                <v-toolbar-title>File Explorer</v-toolbar-title>
                <v-spacer></v-spacer>
            </v-toolbar>
            <v-row no-gutters style="height: calc(100vh - 64px);">
                <v-col cols="4" md="3" style="border-right: 1px solid #ccc; height: 100%; overflow-y: auto;">
                    <div v-if="explorer.tree_loading" class="text-center pa-5">
                        <v-progress-circular indeterminate color="primary" class="my-5"></v-progress-circular>
                        <div>Analyzing file structure...</div>
                    </div>
                     <v-treeview
                        v-if="!explorer.tree_loading"
                        v-model:activated="explorer.activated"
                        :items="explorer.items"
                         :load-children="handleLoadChildren"
                        @update:activated="selectFile"
                        item-title="name"
                        item-value="id"
                         activatable
                        density="compact"
                        style="height: 100%;"
                    >
                    </v-treeview>
               </v-col>
                <v-col cols="8" md="9" style="height: 100%; overflow-y: auto;">
                    <v-container v-if="explorer.selected_node">
                        <v-card flat>
                            <v-card-title>{{ explorer.selected_node.name }}</v-card-title>
                             <v-card-subtitle>Size: {{ formatSize(explorer.selected_node.size) }}</v-card-subtitle>
                            <v-card-actions>
                                <v-btn @click="downloadFile(explorer.selected_node)" color="primary">Download</v-btn>
                            </v-card-actions>
                             <v-divider class="my-4"></v-divider>
                            <v-card-text :class="{ 'pa-0': explorer.preview_type === 'code' }">
                                <div v-if="explorer.loading_preview" class="text-center">
                                     <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                </div>
                                <div v-else-if="explorer.preview_type === 'image'">
                                     <v-img :src="explorer.preview_content" contain max-height="600"></v-img>
                                </div>
                                <div v-else-if="explorer.preview_type === 'code'">
                                    <pre class="language-css" style="max-height: 60vh;"><code v-html="explorer.preview_content"></code></pre>
                                </div>
                                 <div v-else-if="explorer.preview_type === 'error'">
                                    <v-alert type="error" variant="tonal">{{ explorer.preview_content }}</v-alert>
                                </div>
                                 <div v-else-if="explorer.preview_type === 'info_folder'">
                                    <v-alert class="bg-primary">Folder contains {{ explorer.selected_node.stats.fileCount }} files totaling {{ formatSize(explorer.selected_node.stats.totalSize) }}</v-alert>
                                </div>
                                 <div v-else-if="explorer.preview_type === 'info'">
                                    <v-alert class="bg-primary">No preview available for this file type.</v-alert>
                                 </div>
                            </v-card-text>
                        </v-card>
                    </v-container>
                     <v-container v-else class="fill-height d-flex align-center justify-center">
                        <div class="text-center text-grey">
                            <v-icon size="64">mdi-file-outline</v-icon>
                            <p>Select a file to view details</p>
                         </div>
                    </v-container>
                </v-col>
            </v-row>
        </v-card>
    </v-dialog>
    <v-snackbar :timeout="3000" :multi-line="true" v-model="snackbar.show" variant="outlined" style="z-index: 9999999;">
        {{ snackbar.message }}
    </v-snackbar>
    </v-main>
</v-app>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.30.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.30.0/components/prism-core.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.30.0/plugins/autoloader/prism-autoloader.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vue@3.5.22/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@v3.10.5/dist/vuetify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1.12.2/dist/axios.min.js"></script>
<script>
const { createApp } = Vue;
const { createVuetify } = Vuetify;
const vuetify = createVuetify({
    theme: {
        defaultTheme: 'light',
        themes: {
            light: { colors: { primary: '#072e3f', secondary: '#704031' } },
            dark: { colors: { primary: '#072e3f', secondary: '#704031' } },
        }
    },
});
createApp({
    data() {
        return {
            ui_state: "initial",
            loading: false,
            analyzing: false,
            snackbar: { show: false, message: "" },
            manifest_progress: { fetched: 0, total: 0, totalFiles: 0 },
            scan_progress: { total: 1, scanned: 0, status: 'initializing' },
            api_token: "<?php echo \Disembark\Token::get(); ?>",
            home_url: "<?php echo home_url(); ?>",
            backup_token: "",
            database: [],
            files: [],
            files_total: 0,
            backup_ready: false,
            options: {
                database: true,
                files: true,
                exclude_files: "",
                include_database: true,
                include_files: true
            },
            database_progress: { copied: 0, total: 0 },
            files_progress: { copied: 0, total: 0 },
            backup_progress: { copied: 0, total: 0 },
            explorer: {
                show: false,
                items: [],
                activated: [],
                selected_node: null,
                preview_content: '',
                preview_type: 'none',
                loading_preview: false,
                raw_file_list: [],
                tree_loading: false,
            },
            tree_loading: false,
            excluded_nodes: [],
            range_start: null,
            previous_exclusion_string: "",
            database_sort_key: 'table',
            database_search: '',
            included_tables: [],
            db_range_start: null,
            database_backup_queue: [],
            backup_disk_size: 0,
            file_backup_queue: [],
            cleaning_up: false,
            regenerating_token: false,
        }
    },
    watch: {
        ui_state(newState) {
            if (newState === 'connected' && this.explorer.raw_file_list.length > 0 && this.explorer.items.length === 0) {
                this.explorer.items = this.buildInitialTree(this.explorer.raw_file_list);
            }
        },
        async 'explorer.show'(isActive) {
            if (isActive && this.explorer.raw_file_list.length > 0 && this.explorer.items.length === 0) {
                this.explorer.items = this.buildInitialTree(this.explorer.raw_file_list);
            }
        },
    },
    methods: {
        toggleDatabaseSort() {
            this.database_sort_key = this.database_sort_key === 'table' ? 'size' : 'table';
        },
        async fetchBackupSize() {
            try {
                const response = await axios.get(`/wp-json/disembark/v1/backup-size?token=${this.api_token}`);
                this.backup_disk_size = response.data.size;
            } catch (error) {
                console.error("Could not fetch backup size:", error);
                this.backup_disk_size = 0;
            }
        },
        async cleanupBackups() {
            this.cleaning_up = true;
            try {
                await axios.get(`/wp-json/disembark/v1/cleanup?token=${this.api_token}`);
                this.snackbar.message = "Temporary files have been cleaned up.";
                this.snackbar.show = true;
                await this.fetchBackupSize(); // Refresh size

                // Reset UI to the initial state
                this.ui_state = 'initial';
                this.backup_token = "";
                this.database = [];
                this.files = [];
                this.files_total = 0;
                this.excluded_nodes = [];
                this.explorer.raw_file_list = [];
                this.explorer.items = [];
                this.included_tables = [];
                this.manifest_progress = { fetched: 0, total: 0 };
                this.scan_progress = { total: 1, scanned: 0, status: 'initializing' };

            } catch (error) {
                this.snackbar.message = "An error occurred during cleanup.";
                this.snackbar.show = true;
                console.error("Cleanup failed:", error);
            } finally {
                this.cleaning_up = false;
            }
        },
        async regenerateToken() {
            if (!confirm("Are you sure you want to regenerate the token? This will invalidate any existing CLI connections.")) {
                return;
            }
            this.regenerating_token = true;
            try {
                // Pass the current token in the POST body for authorization
                const response = await axios.post(`/wp-json/disembark/v1/regenerate-token`, { token: this.api_token });
                this.api_token = response.data.token; // Update the app's token
                this.snackbar.message = "New token successfully generated.";
                this.snackbar.show = true;
            } catch (error) {
                this.snackbar.message = "An error occurred while regenerating the token.";
                this.snackbar.show = true;
                console.error("Token regeneration failed:", error);
            } finally {
                this.regenerating_token = false;
            }
        },
        toggleTheme() {
            const newTheme = this.$vuetify.theme.global.current.dark ? 'light' : 'dark';
            this.$vuetify.theme.global.name = newTheme;
            localStorage.setItem('theme', newTheme);
            document.body.classList.toggle('disembark-dark-mode', newTheme === 'dark');
        },
        startBackupUI() {
            if (!this.options.include_database && !this.options.include_files) {
                this.snackbar.message = "Nothing to back up. Please toggle on either files or database.";
                this.snackbar.show = true;
                return;
            }
            this.ui_state = 'backing_up';
            this.startBackup();
        },
        showExplorer() {
            this.explorer.show = true;
        },
        async selectFile(newlyActivated) {
            const itemPath = newlyActivated[0];
            if (!itemPath) {
                this.explorer.selected_node = null;
                return;
            }

            const findNode = (nodes, path) => {
                for (const node of nodes) {
                    if (node.id === path) return node;
                    if (node.children) {
                        const found = findNode(node.children, path);
                        if (found) return found;
                    }
                }
                return null;
            };

            const item = findNode(this.explorer.items, itemPath);
            if (!item) { return };
            if (item.children) { // It's a directory
                item.stats = this.calculateFolderStats(item);
                this.explorer.selected_node = item;
                this.explorer.preview_type = 'info_folder';
                return;
            };

            this.explorer.selected_node = item;
            this.explorer.preview_type = 'none';
            this.explorer.loading_preview = true;
            this.explorer.preview_content = '';

            const extension = (item.name.split('.').pop() || '').toLowerCase();
            const imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'];

            // Define the POST data
            const postData = { token: this.api_token, file: item.id};

            if (imageExtensions.includes(extension)) {
                this.explorer.preview_type = 'image';
                try {
                    // Use axios.post
                    const response = await axios.post( '/wp-json/disembark/v1/stream-file', postData, { responseType: 'blob' });
                    this.explorer.preview_content = URL.createObjectURL(response.data);
                } catch (error) {
                    this.explorer.preview_content = 'Error loading image.';
                    this.explorer.preview_type = 'error';
                } finally {
                    this.explorer.loading_preview = false;
                }
            } else {
                this.explorer.preview_type = 'code';
                try {
                    if (item.size > 1024 * 500) { // 500KB limit
                        throw new Error('File is too large to preview.');
                    }
                    // Use axios.post
                    const response = await axios.post('/wp-json/disembark/v1/stream-file', postData );
                    let content = (typeof response.data === 'object' && response.data !== null) ? JSON.stringify(response.data, null, 2) : response.data;
                    let language = (extension === 'js') ? 'javascript' : extension;
                    if (Prism.languages[language]) {
                         this.explorer.preview_content = Prism.highlight(content, Prism.languages[language], language);
                    } else {
                        const esc = document.createElement('textarea');
                        esc.textContent = content;
                        this.explorer.preview_content = esc.innerHTML;
                    }
                } catch (error) {
                    this.explorer.preview_content = error.message || 'Error loading file content.';
                    this.explorer.preview_type = 'error';
                } finally {
                    this.explorer.loading_preview = false;
                }
            }
        },
        async downloadFile(node) {
            if (!node) return;

            this.snackbar.message = `Preparing download for ${node.name}...`;
            this.snackbar.show = true;

            const postData = {
                token: this.api_token,
                file: node.id
            };

            try {
                const response = await axios.post(
                    '/wp-json/disembark/v1/stream-file',
                    postData,
                    { responseType: 'blob' }
                );

                // Create a new Blob object using the response data
                const blob = new Blob([response.data], { type: response.headers['content-type'] });
                const url = window.URL.createObjectURL(blob);

                // Create a temporary link element to trigger the download
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', node.name); // Set the download filename
                document.body.appendChild(link);
                link.click();

                // Clean up by removing the link and revoking the blob URL
                link.parentNode.removeChild(link);
                window.URL.revokeObjectURL(url);

            } catch (error) {
                console.error("Download failed:", error);
                this.snackbar.message = `Could not download ${node.name}. An error occurred.`;
                this.snackbar.show = true;
            }
        },
        calculateFolderStats(folderNode) {
            const stats = { fileCount: 0, totalSize: 0 };
            if (!folderNode) return stats;

            const folderPrefix = folderNode.id + '/';
            this.explorer.raw_file_list.forEach(file => {
                if (file.type === 'file' && file.name.startsWith(folderPrefix)) {
                    stats.fileCount++;
                    stats.totalSize += file.size || 0;
                }
            });
            return stats;
        },
        isTableIncluded(table) {
            return this.included_tables.some(t => t.table === table.table);
        },
        toggleTableExclusion(table) {
            const index = this.included_tables.findIndex(t => t.table === table.table);
            if (index > -1) {
                this.included_tables.splice(index, 1);
            } else {
                // Find the original table object to maintain all its properties
                const originalTable = this.database.find(t => t.table === table.table);
                if (originalTable) {
                    this.included_tables.push(originalTable);
                }
            }
        },
        isNodeExcluded(node) {
            const excludedPaths = new Set(this.excluded_nodes.map(n => n.id));
            if (excludedPaths.has(node.id)) return true;
            
            // Check if any parent is excluded
            const pathParts = node.id.split('/');
            for (let i = 1; i < pathParts.length; i++) {
                const parentPath = pathParts.slice(0, i).join('/');
                if (excludedPaths.has(parentPath)) {
                    return true;
                }
            }
            return false;
        },
        toggleFileExclusion(node) {
            const index = this.excluded_nodes.findIndex(n => n.id === node.id);
            if (index > -1) {
                this.excluded_nodes.splice(index, 1);
            } else {
                this.excluded_nodes.push(node);
            }
        },
        buildInitialTree(files) {
            const tree = [];
            const lookup = {};

            files.forEach(file => {
                if (!file || !file.name) return;
                const pathParts = file.name.split('/');
                const topLevelPart = pathParts[0];
                const topLevelId = topLevelPart;

                if (!lookup[topLevelId]) {
                    const isDirectory = pathParts.length > 1;
                    const newNode = { id: topLevelId, name: topLevelPart, size: file.size || 0 };
                    if (isDirectory) {
                         newNode.children = [];
                    }
                    lookup[topLevelId] = newNode;
                    tree.push(newNode);
                } else {
                    if (lookup[topLevelId].children === undefined) {
                         lookup[topLevelId].children = [];
                    }
                    lookup[topLevelId].size += file.size || 0;
                }
            });
            tree.sort((a, b) => {
                const aIsDir = !!a.children;
                const bIsDir = !!b.children;
                if (aIsDir !== bIsDir) return aIsDir ? -1 : 1;
                return a.name.localeCompare(b.name);
            });
            return tree;
        },
        handleLoadChildren(item) {
            const directChildren = {};
            this.explorer.raw_file_list.forEach(file => {
                if (file.name.startsWith(item.id + '/')) {
                    const relativePath = file.name.substring((item.id + '/').length);
                    const childPart = relativePath.split('/')[0];
                    const childId = item.id + '/' + childPart;
                    const isDirectory = relativePath.split('/').length > 1;

                    if (!directChildren[childId]) {
                        directChildren[childId] = { id: childId, name: childPart, size: file.size || 0 };
                         if (isDirectory) {
                            directChildren[childId].children = [];
                        }
                    } else {
                        directChildren[childId].size += file.size || 0;
                        if (isDirectory && !directChildren[childId].children) {
                            directChildren[childId].children = [];
                         }
                    }
                }
            });
            const childrenArray = Object.values(directChildren);
            childrenArray.sort((a, b) => {
                const aIsDir = !!a.children;
                const bIsDir = !!b.children;
                if (aIsDir !== bIsDir) return aIsDir ? -1 : 1;
                return a.name.localeCompare(b.name);
            });
            item.children.push(...childrenArray);
        },
        handleItemClick(item, event) {
            if (event.shiftKey && this.range_start) {
                const range_end = item;
                const startIndex = this.explorer.raw_file_list.findIndex(f => f.name === this.range_start.id);
                const endIndex = this.explorer.raw_file_list.findIndex(f => f.name === range_end.id);
                if (startIndex === -1 || endIndex === -1) {
                    this.snackbar.message = "Could not find range markers in file list.";
                    this.snackbar.show = true;
                    return;
                }

                const start = Math.min(startIndex, endIndex);
                const end = Math.max(startIndex, endIndex);
                const pathsInRange = this.explorer.raw_file_list.slice(start, end + 1).map(f => f.name);
                
                const allLoadedNodes = this.getLoadedNodes(this.explorer.items);
                const nodesInRange = allLoadedNodes.filter(node => pathsInRange.includes(node.id));
                
                const combinedNodes = [...this.excluded_nodes];
                const existingIds = new Set(combinedNodes.map(n => n.id));
                nodesInRange.forEach(node => {
                    if (!existingIds.has(node.id)) {
                        combinedNodes.push(node);
                    }
                });
                this.excluded_nodes = combinedNodes;
                this.range_start = null;

            } else {
                this.range_start = item;
            }
        },
        handleDbItemClick(clickedTable, event) {
            if (event.shiftKey && this.db_range_start) {
                const range_end = clickedTable;
                const startIndex = this.filteredDatabase.findIndex(t => t.table === this.db_range_start.table);
                const endIndex = this.filteredDatabase.findIndex(t => t.table === range_end.table);
                if (startIndex === -1 || endIndex === -1) return;

                const start = Math.min(startIndex, endIndex);
                const end = Math.max(startIndex, endIndex);
                const tablesInRange = this.filteredDatabase.slice(start, end + 1);
                
                // Determine if we are including or excluding based on the clicked item's state
                const isIncluding = !this.isTableIncluded(clickedTable);
                tablesInRange.forEach(tableInRange => {
                    const isCurrentlyIncluded = this.isTableIncluded(tableInRange);
                    if (isIncluding && !isCurrentlyIncluded) {
                        this.toggleTableExclusion(tableInRange);
                    } else if (!isIncluding && isCurrentlyIncluded) {
                        this.toggleTableExclusion(tableInRange);
                    }
                });
            } else {
                this.db_range_start = clickedTable;
                // The single click action is now handled by the button, so we can leave this empty
                // or add other functionality like row highlighting in the future.
            }
        },
        getLoadedNodes(nodes) {
            let flatList = [];
            for (const node of nodes) {
                flatList.push(node);
                if (node.children && node.children.length > 0) {
                    flatList = flatList.concat(this.getLoadedNodes(node.children));
                }
            }
            return flatList;
        },
        sortFileList(files) {
            if (!files) return [];
            files.sort((a, b) => {
                const a_is_dir = a.type !== 'file';
                const b_is_dir = b.type !== 'file';
                if (a_is_dir && !b_is_dir) return -1;
                if (!a_is_dir && b_is_dir) return 1;
                return a.name.localeCompare(b.name);
            });
            return files;
        },
        async backupFiles() {
            if ( this.backup_progress.copied == this.backup_progress.total ) {
                this.loading = false
                this.backup_ready = true
                this.ui_state = 'initial';
                this.fetchBackupSize(); // Refresh disk size
                return
            }

            let chunk = this.file_backup_queue[ this.backup_progress.copied ];
            let data = {
                token: this.api_token,
                backup_token: this.backup_token,
                files: chunk
            }

            try {
                const response = await axios.post( '/wp-json/disembark/v1/zip-sync-files', data)
                
                if ( !response.data || typeof response.data !== 'string' || !response.data.startsWith('http') ) {
                    let errorMsg = 'Did not receive a valid zip URL.';
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    throw new Error(errorMsg);
                }
                
                if ( this.options.include_files ) {
                     this.files_progress.copied += chunk.length
                }
                this.backup_progress.copied++;
                this.backupFiles();

            } catch(error) {
                 let chunk_name = `chunk ${this.backup_progress.copied + 1}`;
                 this.snackbar.message = `Could not zip ${chunk_name}: ${error.message}. Retrying...`
                 this.snackbar.show = true
                 
                 setTimeout(() => {
                    this.backupFiles()
                 }, 3000);
            }
        },
        backupDatabase() {
            if (this.database_progress.copied == this.database_backup_queue.length) {
                if (this.options.include_files) {
                    this.backupFiles();
                } else {
                    this.loading = false;
                    this.backup_ready = true;
                    this.ui_state = 'initial';
                }
                // Check if any batches or single-part tables were processed
                const hasBatchesOrSinglePartTables = this.database_backup_queue.some(item => item.type === 'batch' || (item.type === 'table' && !item.table.parts));
                if (hasBatchesOrSinglePartTables) {
                    this.zipDatabase();
                }
                return;
            }

            let item = this.database_backup_queue[this.database_progress.copied];
            let data = {
                token: this.api_token,
                backup_token: this.backup_token,
            };

            if (item.type === 'batch') {
                // --- Handle Batch ---
                const tableNames = item.tables.map(t => t.table);
                data.tables = tableNames;
                // Set all tables in this batch to running
                item.tables.forEach(t => t.running = true);

                axios.post(`/wp-json/disembark/v1/export-database-batch`, data).then(response => {
                    if (response.data == "") {
                        this.snackbar.message = `Could not backup batch ${this.database_progress.copied + 1}.`;
                        this.snackbar.show = true;
                        this.loading = false;
                        item.tables.forEach(t => t.running = false); // Reset on failure
                        return;
                    }

                    this.database_progress.copied = this.database_progress.copied + 1;
                    // Set all tables in this batch to done
                    item.tables.forEach(t => {
                        t.running = false;
                        t.done = true;
                        t.completion_time = new Date().getTime();
                    });
                    this.backupDatabase(); // Process next item

                }).catch(error => {
                    this.snackbar.message = `Could not backup batch. Retrying...`;
                    this.snackbar.show = true;
                    item.tables.forEach(t => t.running = false); // Reset on failure
                    this.backupDatabase(); // Retry
                });

            } else if (item.type === 'table') {
                // --- Handle Single Large Table (with or without parts) ---
                let table = item.table;
                data.table = table.table;
                table.running = true;

                if (table.parts) {
                    table.current = (table.current || 0) + 1;
                    data.parts = table.current;
                    data.rows_per_part = table.rows_per_part;
                }

                axios.post(`/wp-json/disembark/v1/export/database/${table.table}`, data).then(response => {
                    if (response.data == "") {
                        this.snackbar.message = `Could not backup table ${table.table}.`;
                        this.snackbar.show = true;
                        this.loading = false;
                        table.running = false;
                        return;
                    }

                    if (table.parts && table.current !== table.parts) {
                        // This is a multi-part table, but not the last part.
                        // Zip the part we just got.
                        axios.post('/wp-json/disembark/v1/zip-database', data).then(() => {
                            // Don't mark as "running = false", the table is still processing.
                            this.backupDatabase(); // Call for the next part
                        });
                        return;
                    }

                    // This is the last part of a multi-part table, or a single-part table
                    this.database_progress.copied = this.database_progress.copied + 1;
                    table.running = false;
                    table.done = true;
                    table.completion_time = new Date().getTime();
                    this.backupDatabase(); // Call for next item in queue

                }).catch(error => {
                    this.snackbar.message = `Could not backup table ${table.table}. Retrying...`;
                    if (table.parts) {
                        table.current = table.current - 1; // Decrement part counter
                    }
                    this.snackbar.show = true;
                    table.running = false; // Reset running on failure
                    this.backupDatabase(); // Retry
                });
            }
        },
        zipDatabase() {
            axios.post( '/wp-json/disembark/v1/zip-database', {
                token: this.api_token,
                backup_token: this.backup_token
            }).then( response => {
               if ( response.data == "" ) {
                    this.snackbar.message = `Could not zip database.`
                    this.snackbar.show = true
                }
             })
        },
        resetBackupState() {
            this.backup_ready = false;
            this.database_backup_queue = [];
            this.file_backup_queue = [];
            this.database_progress = { copied: 0, total: 0 };
            this.files_progress = { copied: 0, total: 0 };
            this.backup_progress = { copied: 0, total: 0 };
            this.database.forEach(table => {
                table.running = false;
                table.done = false;
                table.completion_time = null;
                if (table.parts) {
                    table.current = 0;
                }
            });
        },
        handleMainAction() {
            if (this.ui_state === 'initial') {
                this.connect();
            } else if (this.ui_state === 'connected') {
                this.startBackupUI();
            }
        },
        async fetchAndProcessManifests(manifests) {
            const concurrencyLimit = 3;
            const allResults = [];
            for (let i = 0; i < manifests.length; i += concurrencyLimit) {
                const chunk = manifests.slice(i, i + concurrencyLimit);
                const promises = chunk.map(async (manifestChunk) => {
                    const response = await axios.get(manifestChunk.url);
                    if (Array.isArray(response.data)) {
                        this.manifest_progress.fetched++;
                        return { data: response.data, count: manifestChunk.count };
                    }
                    throw new Error(`Invalid data received for ${manifestChunk.name}`);
                });
                try {
                    const chunkResults = await Promise.all(promises);
                    allResults.push(...chunkResults);
                } catch (error) {
                    this.snackbar.message = `Error processing file list. Please try again.`;
                    this.snackbar.show = true;
                    throw new Error("Manifest fetching failed.");
                }
            }

            this.explorer.raw_file_list = [];
            this.files_total = 0;
            const allFiles = [];
            for (const result of allResults) {
                allFiles.push(...result.data);
                this.files_total += result.count;
            }
            this.explorer.raw_file_list = this.sortFileList(allFiles);
        },
        async connect() {
            this.analyzing = true;
            this.backup_ready = false;
            this.backup_token = "";
            this.database = [];
            this.options = { database: true, files: true, exclude_files: "", include_database: true, include_files: true };
            this.files = [];
            this.files_total = 0;
            this.excluded_nodes = [];
            this.explorer.raw_file_list = [];
            this.manifest_progress = { fetched: 0, total: 0 };
            this.scan_progress = { total: 1, scanned: 0, status: 'initializing' };
            try {
                const dbResponse = await axios.get(`/wp-json/disembark/v1/database?token=${this.api_token}`);
                if (!dbResponse.data || dbResponse.data.error) {
                    throw new Error(dbResponse.data.error || "Could not fetch database info.");
                }
                this.database = dbResponse.data.map(table => ({...table, included: true}));
                this.included_tables = [...this.database];

                const bytes = new Uint8Array(20);
                window.crypto.getRandomValues(bytes);
                this.backup_token = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('').substring(0, 12);
                this.tree_loading = true; // Show loader for tree
                this.files = await this.runManifestGeneration();
                this.scan_progress.status = 'loading';
                this.manifest_progress.fetched = 0; 
                this.manifest_progress.total = this.files.length;

                await this.fetchAndProcessManifests(this.files);
                this.explorer.items = this.buildInitialTree(this.explorer.raw_file_list);
                this.tree_loading = false; // Hide loader for tree
                this.ui_state = 'connected';
            } catch (error) {
                this.snackbar.message = `Could not analyze site. ${error.message}`;
                this.snackbar.show = true;
                this.ui_state = 'initial';
                this.tree_loading = false;
            } finally {
                this.analyzing = false;
                this.fetchBackupSize();
            }
        },
        async startBackup() {
            this.resetBackupState(); 
            
            // --- 1. Database Queue (Unchanged) ---
            this.database_backup_queue = [];
            const large_tables = [];
            const small_tables = [];
            const max_size = 209715200; // 200 MB
            const max_rows = 1000000; // 1 million rows

            this.included_tables.forEach(table => {
                const table_size = parseFloat(table.size) || 0;
                const row_count = parseInt(table.row_count) || 0;
                const originalTable = this.database.find(t => t.table === table.table);

                if ((table_size > max_size || row_count > max_rows) && row_count > 0) {
                    const parts_by_size = Math.ceil(table_size / max_size);
                    const parts_by_rows = Math.ceil(row_count / max_rows);
                    const parts = Math.max(parts_by_size, parts_by_rows);
                
                    originalTable.parts = parts;
                    originalTable.current = 0;
                    originalTable.rows_per_part = Math.ceil(row_count / parts);
                    large_tables.push({ type: 'table', table: originalTable });
                } else {
                    if (originalTable) originalTable.parts = 0;
                    small_tables.push(originalTable);
                }
            });
            
            const small_table_batches = [];
            let current_batch = [];
            let current_batch_size = 0;
            
            small_tables.forEach(table => {
                const table_size = parseFloat(table.size) || 0;
                if (current_batch_size + table_size > max_size && current_batch.length > 0) {
                    small_table_batches.push({ type: 'batch', tables: current_batch, size: current_batch_size });
                    current_batch = [];
                    current_batch_size = 0;
                }
                current_batch.push(table);
                current_batch_size += table_size;
            });
            if (current_batch.length > 0) {
                small_table_batches.push({ type: 'batch', tables: current_batch, size: current_batch_size });
            }

            this.database_backup_queue = [...small_table_batches, ...large_tables];
            this.database_progress.total = this.database_backup_queue.length;
            
            // --- 2. File Filtering & Chunking (New Logic) ---
            const selectedPaths = new Set(this.excluded_nodes.map(node => node.id));
            const minimalExclusionPaths = this.excluded_nodes
                .map(node => node.id)
                .filter(path => {
                    let parent = path.substring(0, path.lastIndexOf('/'));
                    while (parent) {
                        if (selectedPaths.has(parent)) {
                            return false;
                        }
                        parent = parent.substring(0, parent.lastIndexOf('/'));
                    }
                    return true;
                });

            // Filter the raw list
            const files_to_zip = this.explorer.raw_file_list.filter(file => {
                if (!file.type || file.type !== 'file') return false;
                for (const exclude_path of minimalExclusionPaths) {
                    if (file.name === exclude_path || file.name.startsWith(exclude_path + '/')) {
                        return false;
                    }
                }
                return true;
            });

            // Chunk the filtered list
            const file_chunks = [];
            let chunk_current = [];
            let chunk_current_size = 0;
            const file_chunk_size = 2500; // 2500 files default (like CLI)
            const file_chunk_max_size = 524288000; // 500MB default (like CLI)

            for (const file of files_to_zip) {
                const file_size = file.size || 0;

                if (file_size > file_chunk_max_size) {
                    if (chunk_current.length > 0) file_chunks.push(chunk_current);
                    file_chunks.push([file]);
                    chunk_current = [];
                    chunk_current_size = 0;
                    continue;
                }
                if ( (chunk_current.length > 0 && chunk_current_size + file_size > file_chunk_max_size) || 
                     (chunk_current.length >= file_chunk_size) ) {
                    file_chunks.push(chunk_current);
                    chunk_current = [file];
                    chunk_current_size = file_size;
                } else {
                    chunk_current.push(file);
                    chunk_current_size += file_size;
                }
            }
            if (chunk_current.length > 0) {
                file_chunks.push(chunk_current);
            }
            
            this.file_backup_queue = file_chunks; 
            this.backup_progress.total = this.file_backup_queue.length; // Total *chunks*
            
            // --- 3. Start Backup ---
            this.analyzing = false;
            this.loading = true;

            if (this.options.include_database && this.included_tables.length > 0) {
                this.backupDatabase();
            } else if (this.options.include_files && this.file_backup_queue.length > 0) {
                this.backupFiles();
            } else {
                this.loading = false;
                this.snackbar.message = "Nothing to back up. Please include either files or database tables.";
                this.snackbar.show = true;
                this.ui_state = 'connected'; // Go back to connected state
            }
        },
        async runManifestGeneration() {
            try {
                await axios.post('/wp-json/disembark/v1/regenerate-manifest', { token: this.api_token, backup_token: this.backup_token, step: 'initiate' });
                this.scan_progress.status = 'scanning';
                let scan_complete = false;
                while (!scan_complete) {
                    const scanResponse = await axios.post('/wp-json/disembark/v1/regenerate-manifest', { token: this.api_token, backup_token: this.backup_token, step: 'scan', exclude_files: this.options.exclude_files });
                    if (scanResponse.data.status === 'scan_complete') {
                        scan_complete = true;
                    }
                    this.scan_progress.total = scanResponse.data.total_dirs;
                    this.scan_progress.scanned = scanResponse.data.scanned_dirs;
                }

                this.scan_progress.status = 'chunking';
                const chunkifyResponse = await axios.post('/wp-json/disembark/v1/regenerate-manifest', { token: this.api_token, backup_token: this.backup_token, step: 'chunkify' });
                const total_chunks = chunkifyResponse.data.total_chunks;
                this.manifest_progress.total = total_chunks;

                for (let i = 1; i <= total_chunks; i++) {
                    await axios.post('/wp-json/disembark/v1/regenerate-manifest', { token: this.api_token, backup_token: this.backup_token, step: 'process_chunk', chunk: i });
                    this.manifest_progress.fetched = i;
                }

                const finalizeResponse = await axios.post('/wp-json/disembark/v1/regenerate-manifest', { token: this.api_token, backup_token: this.backup_token, step: 'finalize' });
                return finalizeResponse.data;
            } catch (error) {
                console.error("Manifest generation failed:", error);
                throw new Error("Could not regenerate the file manifest. " + error.message);
            }
        },
        copyText( value ) {
            var clipboard = document.getElementById("clipboard");
            clipboard.value = value;
            clipboard.focus()
            clipboard.select()
            document.execCommand("copy");
            this.snackbar.message = "Copied to clipboard.";
            this.snackbar.show = true;
        },
        formatSize (fileSizeInBytes) {
            if ( fileSizeInBytes == null ) { return 0; }
            var i = -1;
            var byteUnits = [' kB', ' MB', ' GB', ' TB', 'PB', 'EB', 'ZB', 'YB'];
            do {
                fileSizeInBytes = fileSizeInBytes / 1024;
                i++;
            } while (fileSizeInBytes > 1024);
            return Math.max(fileSizeInBytes, 0.1).toFixed(1) + byteUnits[i];
        },
        formatLargeNumbers (number) {
            if ( isNaN(number) || number == null ) {
                return null;
            } else {
                return number.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
            }
        },
    },
    computed: {
        cliCommands() {
            if (!this.home_url || !this.api_token) return {}; // Return empty object
            
            // Base Commands
            const connectCommand = `disembark connect ${this.home_url} ${this.api_token}`;
            let backupCommand = `disembark backup ${this.home_url}`;
            let syncCommand = `disembark sync ${this.home_url}`;

            // Add File Exclusions
            const selectedPaths = new Set(this.excluded_nodes.map(node => node.id));
            const minimalExclusionPaths = this.excluded_nodes
                .map(node => node.id)
                .filter(path => {
                    let parent = path.substring(0, path.lastIndexOf('/'));
                    while (parent) {
                        if (selectedPaths.has(parent)) {
                            return false;
                        }
                        parent = parent.substring(0, parent.lastIndexOf('/'));
                    }
                    return true;
                });

            if (minimalExclusionPaths.length > 0) {
                const fileExcludes = minimalExclusionPaths.map(path => `-x "${path}"`).join(' ');
                backupCommand += ` ${fileExcludes}`;
                syncCommand += ` ${fileExcludes}`;
            }

            // Add Database Exclusions
            const includedTableNames = new Set(this.included_tables.map(t => t.table));
            const excludedTableNames = this.database
                .filter(table => !includedTableNames.has(table.table))
                .map(table => table.table);

            if (excludedTableNames.length > 0) {
                const tableExcludes = `--exclude-tables=${excludedTableNames.join(',')}`;
                backupCommand += ` ${tableExcludes}`;
                syncCommand += ` ${tableExcludes}`;
            }

            // Add Skip DB flag
            if (!this.options.include_database) {
                backupCommand += ` --skip-db`;
                syncCommand += ` --skip-db`;
            }

            // Add Skip Files flag (Add this block)
            if (!this.options.include_files) {
                backupCommand += ` --skip-files`;
                syncCommand += ` --skip-files`;
            }

            // Only add the session ID if the manifest is synced
            let sessionIdFlag = '';
            if (this.backup_token) {
                sessionIdFlag = ` --session-id=${this.backup_token}`;
            }

            // Return an object with each command
            return {
                connect: connectCommand,
                backup: backupCommand + sessionIdFlag,
                sync: syncCommand + sessionIdFlag
            };
        },
        cliInstall() {
            return `wget https://github.com/DisembarkHost/disembark-cli/releases/latest/download/disembark.phar\nchmod +x disembark.phar\nsudo mv disembark.phar /usr/local/bin/disembark`;
        },
        downloadUrl() {
            if (!this.explorer.selected_node) return '#';
            return `/wp-json/disembark/v1/stream-file?token=${encodeURIComponent(this.api_token)}&file=${encodeURIComponent(this.explorer.selected_node.id)}`;
        },
        isDarkMode() {
            if (!this.$vuetify || !this.$vuetify.theme) return false;
            return this.$vuetify.theme.global.current.dark;
        },
        filesProgress() {
            if (this.exclusionReport.remainingFiles === 0) {
                return 0;
            }
            return (this.files_progress.copied / this.exclusionReport.remainingFiles) * 100;
        },
        databaseProgress() {
            if (!this.database_backup_queue.length) return 0;
            return this.database_progress.copied / this.database_backup_queue.length * 100;
        },
        totalDatabaseSize() {
            if (!this.included_tables || this.included_tables.length === 0) return 0;
            return this.included_tables.map(item => parseInt(item.size) || 0).reduce((prev, next) => prev + next, 0);
        },
        sortedDatabase() {
            if (!this.database) return [];
            return [...this.database].sort((a, b) => {
                if (this.database_sort_key === 'size') {
                    return (b.size || 0) - (a.size || 0);
                }
                return a.table.localeCompare(b.table);
            });
        },
        filteredDatabase() {
            if (!this.database) return [];

            // 1. Start with a mutable copy of the complete database list
            let tables = [...this.database];

            // 2. Apply the "live log" sorting logic
            tables.sort((a, b) => {
                const getStatusScore = (item) => {
                    if (item.running) return 2; // Highest priority
                    if (item.done) return 1;    // Medium priority
                    return 0;                   // Lowest priority
                };
                const scoreA = getStatusScore(a);
                const scoreB = getStatusScore(b);

                // Sort by status first (running > done > pending)
                if (scoreA !== scoreB) {
                    return scoreB - scoreA;
                }

                // If both are 'done', sort by completion time (most recent first)
                if (scoreA === 1) {
                    return (b.completion_time || 0) - (a.completion_time || 0);
                }

                // If both are 'pending', apply the user-selected sort (name or size)
                if (this.database_sort_key === 'size') {
                    return (parseInt(b.size) || 0) - (parseInt(a.size) || 0);
                }
                return a.table.localeCompare(b.table);
            });

            // 3. Apply the search filter to the correctly sorted list
            if (this.database_search) {
                const searchLower = this.database_search.toLowerCase();
                tables = tables.filter(table =>
                    table.table.toLowerCase().includes(searchLower)
                );
            }

            return tables;
        },
        migrateCommand() {
            if ( this.backup_token == '' || ! this.backup_ready ) { return "" }
            const command = `curl -sL https://disembark.host/generate-zip | bash -s -- --url="${this.home_url}" --token="${this.api_token}" --backup-token="${this.backup_token}" --cleanup`;
            return command
        },
        displayTables() {
            return [...this.included_tables].sort((a, b) => {
                const getStatusScore = (item) => {
                    if (item.running) return 2; // Highest priority
                    if (item.done) return 1;    // Medium priority
                    return 0;                   // Lowest priority (pending)
                };

                const scoreA = getStatusScore(a);
                const scoreB = getStatusScore(b);

                // If items have different statuses (e.g., one is running, one is done),
                // sort by the status score in descending order.
                if (scoreA !== scoreB) {
                    return scoreB - scoreA;
                }

                // If both items are 'done' (score of 1), sort by their completion
                // time in descending order (most recent first).
                if (scoreA === 1) {
                    return (b.completion_time || 0) - (a.completion_time || 0);
                }

                // Otherwise, they are both pending, so maintain their original order.
                return 0;
            });
        },
        exclusionReport() {
            const report = { totalFiles: 0, totalSize: 0, excludedFiles: 0, excludedSize: 0, remainingFiles: 0, remainingSize: 0 };
            if (!this.explorer.raw_file_list || this.explorer.raw_file_list.length === 0) {
                return report;
            }
            const excludedPaths = new Set(this.excluded_nodes.map(node => node.id));
            this.explorer.raw_file_list.forEach(file => {
                if (file.type === 'file') {
                    report.totalFiles++;
                    report.totalSize += file.size || 0;
                    const pathParts = file.name.split('/');
                     let isExcluded = false;
                    for (let i = 1; i <= pathParts.length; i++) {
                        const currentPath = pathParts.slice(0, i).join('/');
                        if (excludedPaths.has(currentPath)) {
                             isExcluded = true;
                            break;
                        }
                    }
                     if (isExcluded) {
                        report.excludedFiles++;
                        report.excludedSize += file.size || 0;
                    }
                 }
            });
            report.remainingFiles = report.totalFiles - report.excludedFiles;
            report.remainingSize = report.totalSize - report.excludedSize;
            return report;
        }
    },
    mounted() {
        const storedTheme = localStorage.getItem('theme');
        if (storedTheme) {
            this.$vuetify.theme.global.name = storedTheme;
            if (storedTheme === 'dark') {
                document.body.classList.add('disembark-dark-mode');
            }
        }
        this.fetchBackupSize();
    }
}).use(vuetify).mount('#app');
</script>