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
             <v-card max-width="350" class="pa-3">
                <v-card-text class="text-body-2">
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

                    <div v-if="last_scan_stats" class="my-4">
                        <div 
                            :class="['pa-3', 'rounded', 'border', isDarkMode ? 'bg-grey-darken-3' : 'bg-grey-lighten-4']" 
                            :style="{ borderColor: isDarkMode ? 'rgba(255,255,255,0.12) !important' : 'rgba(0,0,0,0.08) !important' }"
                        >
                            <div class="text-subtitle-2 mb-2 font-weight-bold text-high-emphasis">
                                <v-icon size="small" class="mr-1" color="primary">mdi-history</v-icon> 
                                Last Scan Info
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 8px 0; align-items: start;">
                                
                                <div class="text-caption text-medium-emphasis">Files Found</div>
                                <div class="text-caption font-weight-bold text-high-emphasis text-right">
                                    {{ formatLargeNumbers(last_scan_stats.total_files) }}
                                </div>

                                <div class="text-caption text-medium-emphasis">Total Size</div>
                                <div class="text-caption font-weight-bold text-high-emphasis text-right">
                                    {{ formatSize(last_scan_stats.total_size) }}
                                </div>

                                <div class="text-caption text-medium-emphasis pt-1">Scanned</div>
                                <div class="text-caption text-right">
                                    <div class="font-weight-bold text-high-emphasis">
                                        {{ formatTimeAgo(last_scan_stats.timestamp) }}
                                    </div>
                                    <div class="text-disabled" style="font-size: 10px; line-height: 1.2;">
                                        {{ formatDate(last_scan_stats.timestamp) }}
                                    </div>
                                </div>

                            </div>
                        </div>
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
    <v-card v-if="ui_state === 'initial' || ui_state === 'connected'" :style="{ 'max-width': backup_ready ? '750px' : '600px', margin: '0px auto 20px auto', position: 'relative' }" class="pa-3">
        <v-card-text>
        <v-row v-if="ui_state === 'initial'">
            <v-col cols="12">
                <v-btn block color="primary" @click="handleMainAction">
                    Analyze Site & Prepare Backup
                    <v-icon class="ml-2">mdi-magnify-scan</v-icon>
                </v-btn>
                <div v-if="previous_scans.length > 0">
                    <div class="text-caption text-medium-emphasis mb-2 text-uppercase font-weight-bold">Resume Previous Scan</div>
                    <v-alert
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-2 text-caption"
                        icon="mdi-alert-circle-outline"
                    >
                        <strong>Note:</strong> Files may have changed since these scans were created.
                    </v-alert>
                    <v-card variant="outlined" class="mb-2">
                        <v-list density="compact" lines="one">
                            <v-list-item
                                v-for="session in previous_scans"
                                :key="session.token"
                                @click="resumeSession(session)"
                                link
                            >
                                <template v-slot:prepend>
                                    <v-icon icon="mdi-history" color="primary" class="mr-2"></v-icon>
                                </template>
                                
                                <v-list-item-title class="font-weight-medium">
                                    {{ formatDate(session.timestamp) }}
                                </v-list-item-title>
                                
                                <v-list-item-subtitle>
                                    {{ formatTimeAgo(session.timestamp) }} • ID: {{ session.token.substring(0, 8) }}...
                                </v-list-item-subtitle>
                                
                                <template v-slot:append>
                                    <v-icon icon="mdi-chevron-right" size="small"></v-icon>
                                </template>
                            </v-list-item>
                        </v-list>
                    </v-card>
                </div>
            </v-col>
        </v-row>
        <v-row v-if="ui_state === 'connected' && !backup_ready">
            <v-col cols="12" sm="6">
                <v-btn block color="primary" @click="showExplorer">
                    <v-icon left class="mr-1">mdi-folder-search-outline</v-icon>
                    Explore Files
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6">
                <v-btn block color="secondary" @click="this.backup_ready = true">
                    Start Backup
                    <v-icon left class="ml-1">mdi-cloud-download</v-icon>
                </v-btn>
            </v-col>
        </v-row>
        <v-card v-if="backup_ready" flat>
            <div class="d-flex align-center mb-4">
                <v-icon color="success" class="mr-2">mdi-check-circle</v-icon>
                <span class="text-h6">Ready to Backup</span>
                <v-spacer></v-spacer>
                <v-btn variant="text" icon size="small" @click="backup_ready = false">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </div>
            
            <p class="text-body-2 mb-3">Run this command in your terminal to process the backup locally:</p>
            
            <div style="position: relative; background: #2d2d2d; border-radius: 4px; padding-right: 38px;">
                <pre style="font-size: 12px; color: #f8f8f2; background: #2d2d2d; padding: 14px; white-space: pre-wrap; word-break: break-all; border-radius: 4px;">{{ migrateCommand }}</pre>
                <v-btn variant="text" icon="mdi-content-copy" @click="copyText( migrateCommand )" style="color: #f8f8f2; position: absolute; top: 5px; right: 5px;"></v-btn>
            </div>
            
            <v-alert type="info" density="compact" variant="tonal" class="mt-4 text-caption">
                This command uses your current settings (Session ID: {{ backup_token }}).
            </v-alert>
        </v-card>
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
        <v-toolbar flat density="compact" class="text-body-2" color="primary">
            <v-icon icon="mdi-console" class="mr-2 ml-4"></v-icon>
            For reuseable backups use the Disembark CLI then use the commands below after configuring your exclusions.
        </v-toolbar>
        
        <v-card-text>
            <div class="mt-0 mb-4 text-caption">
                For the best experience, install 
                <v-menu open-on-hover location="bottom start" :close-on-content-click="false">
                    <template v-slot:activator="{ props }">
                        <span 
                            v-bind="props" 
                            class="text-primary font-weight-bold" 
                            style="cursor: help; text-decoration: underline dotted;"
                        >
                            Disembark CLI
                        </span>
                    </template>
                    <v-sheet elevation="4" rounded min-width="450" class="pa-0">
                        <div style="position: relative; padding-right: 42px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                            <pre style="font-size: 11px; background: transparent; padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliInstall }}</pre>
                            <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliInstall )" style="position: absolute; top: 50%; right: -4px; transform: translateY(-50%);"></v-btn>
                        </div>
                    </v-sheet>
                </v-menu>.
            </div>

            <div style="position: relative;margin-bottom: 14px;padding-right: 42px;background: rgb(var(--v-theme-surface-light));border-radius: 4px;">
                <v-tooltip location="top" activator="parent" text="Connect to this site from the CLI."></v-tooltip>
                <pre style="font-size: 11px;background: rgb(var(--v-theme-surface-light));padding: 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ cliCommands.connect }}</pre>
                <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.connect )" style="position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
            </div>

            <div style="position: relative;margin-bottom: 14px;padding-right: 42px;background: rgb(var(--v-theme-surface-light));border-radius: 4px;">
                <v-tooltip location="top" activator="parent" text="Run a full backup with your selected exclusions."></v-tooltip>
                <pre style="font-size: 11px;background: rgb(var(--v-theme-surface-light));padding: 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ cliCommands.backup }}</pre>
                <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.backup )" style="position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
            </div>

            <div style="position: relative;margin-bottom: 14px;padding-right: 42px;background: rgb(var(--v-theme-surface-light));border-radius: 4px;">
                <v-tooltip location="top" activator="parent" text="Create/update a local mirror with your selected exclusions."></v-tooltip>
                <pre style="font-size: 11px;background: rgb(var(--v-theme-surface-light));padding: 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ cliCommands.sync }}</pre>
                <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.sync )" style="position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
            </div>

            <div style="position: relative;margin-bottom: 14px;padding-right: 42px;background: rgb(var(--v-theme-surface-light));border-radius: 4px;">
                <v-tooltip location="top" activator="parent" text="Browse remote file system disk usage (requires `ncdu`)."></v-tooltip>
                <pre style="font-size: 11px;background: rgb(var(--v-theme-surface-light));padding: 14px;white-space: pre;overflow-x: auto;border-radius: 4px;">{{ cliCommands.ncdu }}</pre>
                <v-btn variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.ncdu )" style="position: absolute;top: 50%;right: -4px;transform: translateY(-50%);"></v-btn>
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
            last_scan_stats: null,
            previous_scans: [],
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
        formatDate(timestamp) {
            if (!timestamp) return '';
            return new Date(timestamp * 1000).toLocaleString();
        },
        formatTimeAgo(timestamp) {
            if (!timestamp) return '';
            const seconds = Math.floor(Date.now() / 1000) - timestamp;
            if (seconds < 60) return 'Just now';
            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return `${minutes}m ago`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours}h ago`;
            return `${Math.floor(hours / 24)}d ago`;
        },
        async resumeSession( session ) {
            this.analyzing = true;
            this.backup_ready = false;
            this.backup_token = session.token;
            
            // Reset State
            this.database = [];
            this.files = [];
            this.files_total = 0;
            this.excluded_nodes = [];
            this.explorer.raw_file_list = [];
            this.scan_progress = { total: 1, scanned: 0, status: 'loading' };

            try {
                // 1. Fetch Database Info
                const dbResponse = await axios.get(this.api_root + 'database', { 
                    params: { token: this.api_token } 
                });
                if (!dbResponse.data || dbResponse.data.error) throw new Error("Could not fetch database info.");
                this.database = dbResponse.data.map(table => ({...table, included: true}));
                this.included_tables = [...this.database];

                // 2. Fetch Manifest directly
                const manifestResponse = await axios.get(this.api_root + 'manifest', { 
                    params: { 
                        token: this.api_token,
                        backup_token: this.backup_token
                    }
                });
                this.files = manifestResponse.data;
                this.tree_loading = true;
                this.manifest_progress.fetched = 0;
                
                // 3. Process the Manifests
                this.manifest_progress.total = manifestResponse.data.length;
                await this.fetchAndProcessManifests(manifestResponse.data);
                
                // 4. Build Tree & UI
                this.explorer.items = this.buildInitialTree(this.explorer.raw_file_list);
                this.tree_loading = false;
                this.ui_state = 'connected';
                
                this.snackbar.message = "Session resumed successfully.";
                this.snackbar.show = true;

            } catch (error) {
                this.snackbar.message = `Could not resume session. ${error.message}`;
                this.snackbar.show = true;
                this.ui_state = 'initial';
                this.backup_token = ""; // Reset on fail
            } finally {
                this.analyzing = false;
            }
        },
        async fetchBackupSize() {
            try {
                const response = await axios.get(`/wp-json/disembark/v1/backup-size?token=${this.api_token}`);
                this.backup_disk_size = response.data.size;
                this.last_scan_stats = response.data.scan_stats;
                this.previous_scans = response.data.sessions || [];
            } catch (error) {
                console.error("Could not fetch backup size:", error);
                this.backup_disk_size = 0;
                this.last_scan_stats = null;
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
                this.backup_ready = false;
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

            // Extract domain for folder name (remove protocol and trailing slash)
            const domain = this.home_url.replace(/^https?:\/\//, '').replace(/\/$/, '');
            
            // Base Commands
            const connectCommand = `disembark connect ${this.home_url} ${this.api_token}`;
            let backupCommand = `disembark backup ${this.home_url}`;
            let syncCommand = `disembark sync ${this.home_url} "${domain}"`;
            let ncduCommand = `disembark ncdu ${this.home_url}`;

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
                sync: syncCommand + sessionIdFlag,
                ncdu: ncduCommand + sessionIdFlag
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
            
            // 1. Base Runner Command
            let cmd = `curl -sL https://disembark.host/run | bash -s -- backup "${this.home_url}" --token="${this.api_token}" --session-id="${this.backup_token}"`;

            // 2. Database Exclusions
            // Calculate tables that are NOT in the included list
            const includedSet = new Set(this.included_tables.map(t => t.table));
            const excludedTables = this.database
                .filter(t => !includedSet.has(t.table))
                .map(t => t.table);
            
            if (excludedTables.length > 0) {
                cmd += ` --exclude-tables=${excludedTables.join(',')}`;
            }

            // 3. File Exclusions
            // Filter to find minimal parent paths to keep command clean
            const selectedPaths = new Set(this.excluded_nodes.map(node => node.id));
            const minimalExclusions = this.excluded_nodes
                .map(node => node.id)
                .filter(path => {
                    let parent = path.substring(0, path.lastIndexOf('/'));
                    while (parent) {
                        if (selectedPaths.has(parent)) return false;
                        parent = parent.substring(0, parent.lastIndexOf('/'));
                    }
                    return true;
                });
            
            if (minimalExclusions.length > 0) {
                cmd += " " + minimalExclusions.map(path => `-x "${path}"`).join(' ');
            }

            // 4. Skip Flags
            if (!this.options.include_database) cmd += ` --skip-db`;
            if (!this.options.include_files) cmd += ` --skip-files`;

            return cmd;
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