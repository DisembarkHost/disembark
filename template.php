<div id="app" v-cloak>
<v-app>
    <div style="position: absolute; top: 10px; right: 10px; z-index: 10; display: flex; gap: 4px;">
        <v-menu location="bottom end" :close-on-content-click="false">
             <template v-slot:activator="{ props }">
                 <v-btn icon variant="text" v-bind="props">
                     <v-icon>mdi-tools</v-icon>
                     <v-tooltip location="left" activator="parent" text="Settings"></v-tooltip>
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
                <v-btn block variant="tonal" color="primary" size="large" class="mb-4" @click="showDbExplorer">
                    <v-icon left class="mr-2">mdi-database-search-outline</v-icon>
                    Explore Database
                </v-btn>
                <v-btn block color="primary" @click="handleMainAction" size="large" class="mb-6">
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
            <v-col cols="12" sm="6" v-if="database.length > 0">
                <v-btn block variant="tonal" color="primary" @click="showDbExplorer">
                    <v-icon left class="mr-1">mdi-database-search-outline</v-icon>
                    Explore Database
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6">
                <v-btn block color="primary" @click="showExplorer">
                    <v-icon left class="mr-1">mdi-folder-search-outline</v-icon>
                    Explore Files
                </v-btn>
            </v-col>
            <v-col cols="12" sm="12">
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
    <v-overlay v-model="loading" contained persistent attach="#app" opacity="0.7" class="align-center justify-center" z-index="3000">
        <div class="text-center text-white text-body-1 disembark-overlay-content" style="width: 500px; max-width: 90vw;">
            
            <div class="mb-5"><strong>{{ loading_message }}</strong></div>

            <div v-if="included_tables.length > 0 && this.options.include_database && !is_folder_downloading" class="mb-4">
                <div class="text-left text-body-2 mb-1">Database</div>
                <v-progress-linear v-model="databaseProgress" color="amber" height="25">
                    Copied {{ database_progress.copied }} of {{ database_backup_queue.length }} items
                </v-progress-linear>
            </div>

            <div v-if="exclusionReport.remainingFiles > 0 && !is_folder_downloading">
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
            <v-toolbar v-show="options.include_database" density="compact" flat :color="isDarkMode ? 'surface' : 'white'" class="px-4">
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
            For the best experience, install
            <v-menu open-on-hover location="bottom start" :close-on-content-click="false">
                <template v-slot:activator="{ props }">
                    <span v-bind="props" class="font-weight-bold ml-1" style="cursor: help; text-decoration: underline dotted;">
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
        </v-toolbar>
        <v-card-text>
            <div style="position: relative; margin-bottom: 14px; padding-right: 78px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                <pre style="font-size: 11px; background: rgb(var(--v-theme-surface-light)); padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliCommands.connect }}</pre>
                <v-tooltip location="top" text="Connect to this site from the CLI.">
                    <template v-slot:activator="{ props }">
                        <v-btn size="small" v-bind="props" variant="text" icon="mdi-information-outline" style="position: absolute; top: 50%; right: 38px; transform: translateY(-50%);"></v-btn>
                    </template>
                </v-tooltip>
                <v-btn size="small" variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.connect )" style="position: absolute; top: 50%; right: 2px; transform: translateY(-50%);"></v-btn>
            </div>
            <div style="position: relative; margin-bottom: 14px; padding-right: 78px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                <pre style="font-size: 11px; background: rgb(var(--v-theme-surface-light)); padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliCommands.backup }}</pre>
                <v-tooltip location="top" text="Run a full backup with your selected exclusions.">
                    <template v-slot:activator="{ props }">
                        <v-btn size="small" v-bind="props" variant="text" icon="mdi-information-outline" style="position: absolute; top: 50%; right: 38px; transform: translateY(-50%);"></v-btn>
                    </template>
                </v-tooltip>
                <v-btn size="small" variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.backup )" style="position: absolute; top: 50%; right: 2px; transform: translateY(-50%);"></v-btn>
            </div>
            <div style="position: relative; margin-bottom: 14px; padding-right: 78px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                <pre style="font-size: 11px; background: rgb(var(--v-theme-surface-light)); padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliCommands.sync }}</pre>
                <v-tooltip location="top" text="Create/update a local mirror with your selected exclusions.">
                    <template v-slot:activator="{ props }">
                        <v-btn size="small" v-bind="props" variant="text" icon="mdi-information-outline" style="position: absolute; top: 50%; right: 38px; transform: translateY(-50%);"></v-btn>
                    </template>
                </v-tooltip>
                <v-btn size="small" variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.sync )" style="position: absolute; top: 50%; right: 2px; transform: translateY(-50%);"></v-btn>
            </div>
            <div style="position: relative; margin-bottom: 14px; padding-right: 78px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                <pre style="font-size: 11px; background: rgb(var(--v-theme-surface-light)); padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliCommands.ncdu }}</pre>
                <v-tooltip location="top" text="Browse remote file system disk usage (requires `ncdu`).">
                    <template v-slot:activator="{ props }">
                        <v-btn size="small" v-bind="props" variant="text" icon="mdi-information-outline" style="position: absolute; top: 50%; right: 38px; transform: translateY(-50%);"></v-btn>
                    </template>
                </v-tooltip>
                <v-btn size="small" variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.ncdu )" style="position: absolute; top: 50%; right: 2px; transform: translateY(-50%);"></v-btn>
            </div>
            <div style="position: relative; margin-bottom: 14px; padding-right: 78px; background: rgb(var(--v-theme-surface-light)); border-radius: 4px;">
                <pre style="font-size: 11px; background: rgb(var(--v-theme-surface-light)); padding: 14px; white-space: pre; overflow-x: auto; border-radius: 4px;">{{ cliCommands.info }}</pre>
                <v-tooltip location="top" text="Display connection status, storage usage, and previous sessions.">
                    <template v-slot:activator="{ props }">
                        <v-btn size="small" v-bind="props" variant="text" icon="mdi-information-outline" style="position: absolute; top: 50%; right: 38px; transform: translateY(-50%);"></v-btn>
                    </template>
                </v-tooltip>
                <v-btn size="small" variant="text" icon="mdi-content-copy" @click="copyText( cliCommands.info )" style="position: absolute; top: 50%; right: 2px; transform: translateY(-50%);"></v-btn>
            </div>
        </v-card-text>
    </v-card>
    </div>  
    </v-container>
    <v-dialog v-model="dbExplorer.show" fullscreen :scrim="false" transition="none">
        <v-card>
            <v-toolbar dark color="primary">
                <v-btn icon dark @click="dbExplorer.show = false">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
                <v-toolbar-title>Database Explorer</v-toolbar-title>
            </v-toolbar>
            <v-row no-gutters style="height: calc(100vh - 64px);">
                
                <v-col cols="3" style="border-right: 1px solid #ccc; height: 100%; display: flex; flex-direction: column;">
                    <div class="pa-2 border-b">
                        <v-text-field
                            v-model="dbExplorer.search"
                            label="Search tables..."
                            density="compact"
                            variant="outlined"
                            hide-details
                            prepend-inner-icon="mdi-magnify"
                            clearable
                        ></v-text-field>
                    </div>
                    
                    <div class="d-flex align-center px-2 py-2 border-b" v-if="filteredDbTables.length > 0">
                        <span class="text-caption font-weight-bold text-medium-emphasis" style="cursor: pointer;" @click="toggleSelectAllTables">
                            Select All ({{ filteredDbTables.length }})
                        </span>
                    </div>
                    <div style="flex: 1; overflow-y: auto;">
                        <v-list density="compact" nav select-strategy="classic">
                            <v-list-item
                                v-for="table in filteredDbTables"
                                :key="table.table"
                                :value="table.table"
                                @click="selectDbTable(table.table, $event)"
                                :active="dbExplorer.selectedTables.includes(table.table)"
                                color="primary"
                            >
                                <template v-slot:prepend>
                                    <v-list-item-action start>
                                        <v-checkbox-btn 
                                            :model-value="dbExplorer.selectedTables.includes(table.table)"
                                            density="compact"
                                        ></v-checkbox-btn>
                                    </v-list-item-action>
                                </template>
                                <v-list-item-title>{{ table.table }}</v-list-item-title>
                                <template v-slot:append>
                                    <span class="text-caption text-medium-emphasis">{{ formatSize(table.size) }}</span>
                                </template>
                            </v-list-item>
                        </v-list>
                    </div>
                </v-col>
                
                <v-col cols="9" style="height: 100%; display: flex; flex-direction: column;">

                    <div v-if="dbExplorer.selectedTables.length === 0" class="fill-height d-flex align-center justify-center text-medium-emphasis">
                        <div class="text-center">
                            <v-icon size="64" class="mb-2">mdi-table-search</v-icon>
                            <div>Select tables to view data or export</div>
                        </div>
                    </div>

                    <div v-else-if="dbExplorer.selectedTables.length > 1" class="fill-height d-flex align-center justify-center">
                        <v-card width="500" variant="outlined" class="text-center pa-6">
                        <v-icon size="64" color="primary" class="mb-4">mdi-database-export</v-icon>
                        <h3 class="text-h5 mb-2">Batch Export</h3>
                        <div class="text-subtitle-1 mb-6 text-medium-emphasis">
                            {{ dbExplorer.selectedTables.length }} tables selected
                        </div>
                        
                        <div class="d-flex justify-center mb-6">
                            <v-chip color="primary" variant="outlined" class="ma-1" size="large">
                                Size: {{ formatSize( batchExportStats.size ) }}
                            </v-chip>
                            <v-chip color="primary" variant="outlined" class="ma-1" size="large">
                                Rows: {{ formatLargeNumbers( batchExportStats.rows ) }}
                            </v-chip>
                        </div>

                        <p class="text-body-2 mb-6 text-medium-emphasis">
                            Download a single SQL dump containing all {{ dbExplorer.selectedTables.length }} selected tables.
                        </p>

                        <v-btn 
                            block 
                            color="primary" 
                            size="large" 
                            @click="exportBatchTables"
                            :loading="dbExplorer.exporting"
                        >
                            <v-icon start>mdi-download</v-icon>
                            Download Batch
                        </v-btn>
                    </v-card>
                </div>

                    <template v-else>
                        <div class="border-b">
                            <v-tabs 
                                v-model="dbExplorer.activeTab" 
                                color="primary" 
                                density="compact"
                                @update:model-value="handleDbTabChange"
                            >
                                <v-tab value="rows">Rows</v-tab>
                                <v-tab value="info">Info</v-tab>
                                <v-tab value="export">Export</v-tab>
                            </v-tabs>
                        </div>

                        <v-window v-model="dbExplorer.activeTab" class="flex-grow-1" style="overflow: hidden; min-height: 0;" :transition="false" :reverse-transition="false">
                            <v-window-item value="rows" style="height: 100%;" :transition="false" :reverse-transition="false">
                                <div class="d-flex flex-column" style="height: 100%;">
                                    <div class="d-flex justify-end pa-2 border-b bg-surface flex-shrink-0">
                                        <v-btn color="primary" density="comfortable" variant="flat" @click="openCreateDialog">
                                            <v-icon start>mdi-plus</v-icon>
                                            Add Row
                                        </v-btn>
                                    </div>
                                    <v-data-table-server
                                        v-model:items-per-page="dbExplorer.itemsPerPage"
                                        :headers="dbExplorer.headers"
                                        :items="dbExplorer.rows"
                                        :items-length="dbExplorer.totalItems"
                                        :loading="dbExplorer.loading"
                                        item-value="name"
                                        @update:options="loadDbRows"
                                        @click:row="handleRowClick" hover density="compact"
                                        fixed-header
                                        fixed-footer
                                        style="height: calc(100vh - 170px);"
                                        :items-per-page-options="[100, 500, 1000]"
                                    >
                                        <template v-slot:no-data>
                                        <div class="text-center pa-12">
                                            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-table-off</v-icon>
                                            <div class="text-h6 text-medium-emphasis mb-2">This table is empty</div>
                                            <div class="text-body-2 text-disabled">No rows match your current filters or the table has no data.</div>
                                        </div>
                                        </template>

                                        <template v-slot:loading>
                                        <div class="text-center pa-12">
                                            <v-progress-circular indeterminate color="primary" class="mb-4"></v-progress-circular>
                                            <div>Loading rows...</div>
                                        </div>
                                        </template>
                                    </v-data-table-server>
                                </div>
                            </v-window-item>
                            <v-window-item value="info" :transition="false" :reverse-transition="false">
                                <v-container>
                                    <v-row v-if="dbExplorer.tableStatus">
                                        <v-col cols="4">
                                            <v-card variant="tonal" class="pa-3">
                                                <div class="text-caption text-medium-emphasis">Rows</div>
                                                <div class="text-h6">{{ formatLargeNumbers(dbExplorer.tableStatus.Rows) }}</div>
                                            </v-card>
                                        </v-col>
                                        <v-col cols="4">
                                            <v-card variant="tonal" class="pa-3">
                                                <div class="text-caption text-medium-emphasis">Engine</div>
                                                <div class="text-h6">{{ dbExplorer.tableStatus.Engine }}</div>
                                            </v-card>
                                        </v-col>
                                        <v-col cols="4">
                                            <v-card variant="tonal" class="pa-3">
                                                <div class="text-caption text-medium-emphasis">Collation</div>
                                                <div class="text-h6">{{ dbExplorer.tableStatus.Collation }}</div>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                    
                                    <div class="text-subtitle-2 mt-4 mb-2 text-primary">Table Schema</div>
                                    <v-data-table
                                        :items="dbExplorer.schema"
                                        density="compact"
                                        class="border"
                                    ></v-data-table>
                                </v-container>
                            </v-window-item>
                            <v-window-item value="export" class="fill-height" :transition="false" :reverse-transition="false">
                                <v-container class="fill-height d-flex align-center justify-center">
                                    <v-card width="400" variant="outlined" class="text-center pa-5">
                                        <v-icon size="64" color="primary" class="mb-4">mdi-database-export</v-icon>
                                        <h3 class="text-h5 mb-2">Export {{ dbExplorer.selectedTable }}</h3>
                                        
                                        <div class="d-flex justify-center mb-6">
                                            <v-chip color="primary" variant="outlined" class="ma-1">
                                                Size: {{ formatSize( database.find(t => t.table === dbExplorer.selectedTable)?.size ) }}
                                            </v-chip>
                                            <v-chip color="primary" variant="outlined" class="ma-1">
                                                Rows: {{ formatLargeNumbers( database.find(t => t.table === dbExplorer.selectedTable)?.row_count ) }}
                                            </v-chip>
                                        </div>

                                        <p class="text-body-2 mb-6 text-medium-emphasis">
                                            Download a SQL dump of this specific table. This file will be generated on the server and then downloaded.
                                        </p>

                                        <v-btn 
                                            block 
                                            color="primary" 
                                            size="large" 
                                            @click="exportSingleTable"
                                            :loading="dbExplorer.exporting"
                                        >
                                            <v-icon start>mdi-download</v-icon>
                                            Download Table
                                        </v-btn>
                                    </v-card>
                                </v-container>
                            </v-window-item>
                        </v-window>
                    </template>
                </v-col>
            </v-row>
        </v-card>
    </v-dialog>
    <v-dialog v-model="editDialog.show" max-width="800" persistent>
        <v-card>
            <v-toolbar color="primary" density="compact">
                <v-toolbar-title>{{ editDialog.mode === 'create' ? 'Create Row' : 'Edit Row' }}: {{ dbExplorer.selectedTable }}</v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn icon @click="editDialog.show = false"><v-icon>mdi-close</v-icon></v-btn>
            </v-toolbar>
            
            <v-card-text class="pa-4" style="max-height: 70vh; overflow-y: auto;">
                <v-form ref="editForm">
                    <v-row>
                        <v-col cols="12" v-for="col in dbExplorer.headers" :key="col.key">
                            
                            <v-textarea
                                v-if="isTextarea(col.key)"
                                v-model="editDialog.item[col.key]"
                                :label="col.title"
                                variant="outlined"
                                auto-grow
                                rows="3"
                                density="compact"
                                :hint="getColumnType(col.key)"
                                persistent-hint
                            ></v-textarea>

                            <v-text-field
                                v-else
                                v-model="editDialog.item[col.key]"
                                :label="col.title"
                                variant="outlined"
                                density="compact"
                                :hint="getColumnType(col.key)"
                                persistent-hint
                            ></v-text-field>

                        </v-col>
                    </v-row>
                </v-form>
            </v-card-text>

            <v-card-actions class="pa-4">
                <v-btn 
                    color="error" 
                    variant="text" 
                    @click="deleteRow" 
                    :loading="editDialog.loading"
                    v-if="editDialog.mode === 'edit'"
                >
                    Delete Row
                </v-btn>

                <v-spacer></v-spacer>
                <v-btn variant="text" @click="editDialog.show = false">Cancel</v-btn>
                <v-btn color="primary" @click="saveRow" :loading="editDialog.loading">Save Changes</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
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
                        
                        <v-card flat style="position: relative; min-height: 300px;">
                            <v-overlay v-model="is_folder_downloading" contained persistent class="align-center justify-center">
                            <v-card class="text-center pa-5" width="350" elevation="4" rounded="lg">
                                <div class="text-h6 mb-2 text-primary">Creating Zip Archive</div>
                                <div class="text-body-2 mb-4">{{ loading_message }}</div>
                                
                                <v-progress-linear 
                                    indeterminate 
                                    color="primary" 
                                    height="6" 
                                    rounded 
                                    class="mb-2"
                                ></v-progress-linear>
                                
                                <div class="text-caption text-medium-emphasis">Large folders may take some time.</div>
                            </v-card>
                            </v-overlay>

                            <v-card-title>{{ explorer.selected_node.name }}</v-card-title>
                            <v-card-subtitle>Size: {{ formatSize(explorer.selected_node.size) }}</v-card-subtitle>
                            
                            <v-card-actions>
                                <v-btn @click="downloadFile(explorer.selected_node)" color="primary" variant="tonal" class="mr-2">
                                    <v-icon start>mdi-download</v-icon> Download
                                </v-btn>

                                <v-btn 
                                    @click="openRenameDialog" 
                                    color="primary" 
                                    variant="text" 
                                    class="mr-2"
                                    v-if="!explorer.is_editing"
                                >
                                    <v-icon start>mdi-pencil-outline</v-icon> Rename
                                </v-btn>
                                
                                <v-btn 
                                    v-if="explorer.preview_type === 'code' && !explorer.is_editing" 
                                    @click="enableEditMode" 
                                    color="secondary" 
                                    variant="tonal"
                                >
                                    <v-icon start>mdi-pencil</v-icon> Edit
                                </v-btn>

                                <v-btn 
                                    v-if="explorer.is_editing" 
                                    @click="saveFile" 
                                    color="success" 
                                    :loading="explorer.saving_file"
                                >
                                    <v-icon start>mdi-content-save</v-icon> Save
                                </v-btn>

                                <v-btn 
                                    v-if="explorer.is_editing" 
                                    @click="cancelEdit" 
                                    color="error" 
                                    variant="text"
                                    :disabled="explorer.saving_file"
                                >
                                    Cancel
                                </v-btn>
                            </v-card-actions>
                            <v-divider class="my-4"></v-divider>
                            
                            <v-card-text class="pa-0">
                                <div v-if="explorer.is_editing" class="d-flex flex-column" style="height: 400px;">
                                    <textarea 
                                        v-model="explorer.edit_content" 
                                        style="width: 100%; height: 100%; resize: none; border: none; outline: none; padding: 16px; font-family: monospace; font-size: 12px; line-height: 1.5; background-color: #f5f5f5; color: #333;"
                                        spellcheck="false"
                                    ></textarea>
                                </div>

                                <div v-else>
                                    <div v-if="explorer.preview_type === 'image'" class="d-flex justify-center pa-4">
                                        <img :src="explorer.preview_content" style="max-width: 100%; max-height: 400px;" />
                                    </div>
                                    
                                    <div v-else-if="explorer.preview_type === 'code'" style="position: relative;">
                                        <pre style="margin: 0; height: 400px; overflow: auto; font-size: 12px; padding: 16px;" class="language-"><code v-html="explorer.preview_content"></code></pre>
                                    </div>
                                    
                                    <div v-else-if="explorer.preview_type === 'error'" class="pa-4 text-red">
                                        {{ explorer.preview_content }}
                                    </div>
                                    <div v-else-if="explorer.loading_preview" class="d-flex justify-center align-center" style="height: 400px;">
                                        <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                    </div>
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
    <v-dialog v-model="renameDialog.show" max-width="400">
        <v-card>
            <v-card-title>Rename</v-card-title>
            <v-card-text>
                <v-text-field
                    v-model="renameDialog.newName"
                    label="New Name"
                    variant="outlined"
                    autofocus
                    @keyup.enter="performRename"
                ></v-text-field>
            </v-card-text>
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn color="grey" variant="text" @click="renameDialog.show = false">Cancel</v-btn>
                <v-btn color="primary" @click="performRename" :loading="renameDialog.loading">Rename</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
    <v-dialog v-model="zip_cleanup.show" max-width="500" persistent>
        <v-card>
            <v-card-title class="text-h6">
                <v-icon color="success" class="mr-2">mdi-check-circle</v-icon>
                Download Started
            </v-card-title>
            <v-card-text>
                <p class="mb-3">The folder <strong>{{ zip_cleanup.folder_name }}</strong> was zipped on the server and the download has started.</p>
                <p class="text-caption text-medium-emphasis">Would you like to delete this temporary zip file ({{ formatSize(zip_cleanup.size) }}) from the server now to free up space?</p>
            </v-card-text>
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn variant="text" color="grey" @click="zip_cleanup.show = false">Keep File</v-btn>
                <v-btn color="error" variant="flat" @click="performZipCleanup" :loading="zip_cleanup.loading">Delete File</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
    <v-snackbar :timeout="3000" :multi-line="true" v-model="snackbar.show" variant="outlined" attach="#app" z-index="9999999">
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
            api_root: "<?php echo esc_url_raw( rest_url('disembark/v1/') ); ?>",
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
            dbExplorer: {
                show: false,
                selectedTable: null,
                selectedTables: [],
                search: '',
                activeTab: 'rows',
                schema: [],
                tableStatus: null,
                headers: [],
                rows: [],
                loading: false,
                exporting: false,
                totalItems: 0,
                itemsPerPage: 1000,
                page: 1, 
                sortBy: [] 
            },
            editDialog: {
                show: false,
                loading: false,
                mode: 'edit',
                item: {},     
                original: {}
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
                is_editing: false,
                edit_content: '',
                raw_content: '',
                saving_file: false
            },
            renameDialog: {
                show: false,
                loading: false,
                oldPath: '',
                newName: ''
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
            loading_message: "Backup in progress...",
            is_folder_downloading: false,
            zip_cleanup: {
                show: false,
                file_name: "",
                folder_name: "",
                size: 0,
                loading: false
            },
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
                const response = await axios.get(this.api_root + 'backup-size', { 
                    params: { token: this.api_token } 
                });
                this.backup_disk_size = response.data.size;
                this.last_scan_stats = response.data.scan_stats;
                this.previous_scans = response.data.sessions || [];
            } catch (error) {
                console.error("Could not fetch backup size:", error);
                this.backup_disk_size = 0;
                this.last_scan_stats = null;
                this.previous_scans = [];
            }
        },
        async cleanupBackups() {
            this.cleaning_up = true;
            try {
                await axios.get(`${this.api_root}cleanup`, { 
                    params: { token: this.api_token } 
                });
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
        async showDbExplorer() {
            // Check if we need to fetch data first
            if (this.database.length === 0) {
                this.loading = true; // Show the loading overlay
                this.loading_message = "Fetching database structure...";
                
                try {
                    const dbResponse = await axios.get(`${this.api_root}database`, { 
                        params: { token: this.api_token } 
                    });
                    
                    if (!dbResponse.data || dbResponse.data.error) {
                        throw new Error("Could not fetch database info.");
                    }
                    
                    // Populate the data
                    this.database = dbResponse.data.map(table => ({...table, included: true}));
                    this.included_tables = [...this.database];

                } catch (error) {
                    this.snackbar.message = "Failed to load database: " + error.message;
                    this.snackbar.show = true;
                    this.loading = false;
                    return; // Stop here on error
                } finally {
                    this.loading = false;
                }
            }

            // Open the dialog
            this.dbExplorer.show = true;
        },
        selectDbTable(tableName, event = null) {
            // 1. Handle Multi-select (Ctrl/Cmd or Checkbox click)
            if (event && (event.ctrlKey || event.metaKey || event.target.closest('.v-checkbox-btn'))) {
                const index = this.dbExplorer.selectedTables.indexOf(tableName);
                if (index === -1) {
                    this.dbExplorer.selectedTables.push(tableName);
                } else {
                    this.dbExplorer.selectedTables.splice(index, 1);
                }
            } 
            // 2. Handle Range Select (Shift)
            else if (event && event.shiftKey && this.dbExplorer.selectedTables.length > 0) {
                const lastSelected = this.dbExplorer.selectedTables[this.dbExplorer.selectedTables.length - 1];
                const startIdx = this.filteredDbTables.findIndex(t => t.table === lastSelected);
                const endIdx = this.filteredDbTables.findIndex(t => t.table === tableName);
                
                if (startIdx !== -1 && endIdx !== -1) {
                    const start = Math.min(startIdx, endIdx);
                    const end = Math.max(startIdx, endIdx);
                    const range = this.filteredDbTables.slice(start, end + 1).map(t => t.table);
                    
                    // Add unique tables from range
                    range.forEach(t => {
                        if (!this.dbExplorer.selectedTables.includes(t)) {
                            this.dbExplorer.selectedTables.push(t);
                        }
                    });
                }
            }
            // 3. Default: Single Select (Clears others)
            else {
                this.dbExplorer.selectedTables = [tableName];
            }

            // Sync legacy selectedTable for the single view logic
            if (this.dbExplorer.selectedTables.length === 1) {
                this.dbExplorer.selectedTable = this.dbExplorer.selectedTables[0];
                
                // Load data for the single view immediately
                if (this.dbExplorer.activeTab === 'rows') {
                    this.dbExplorer.rows = []; // Clear previous
                    this.loadDbRows({ page: 1, itemsPerPage: this.dbExplorer.itemsPerPage });
                } else if (this.dbExplorer.activeTab === 'info') {
                    this.loadTableSchema();
                }
            } else {
                this.dbExplorer.selectedTable = null; // Hide single view
            }
        },
        handleDbTabChange( val ) {
            if ( !this.dbExplorer.selectedTable ) return;
            
            if ( val === 'rows' && this.dbExplorer.rows.length === 0 ) {
                this.loadDbRows({ page: 1, itemsPerPage: this.dbExplorer.itemsPerPage });
            } else if ( val === 'info' && this.dbExplorer.schema.length === 0 ) {
                this.loadTableSchema();
            }
        },
        toggleSelectAllTables() {
            // Get list of currently visible table names from the filter
            const visibleTableNames = this.filteredDbTables.map(t => t.table);
            
            // Check if we are selecting or deselecting based on current state
            const allVisibleSelected = visibleTableNames.every(t => this.dbExplorer.selectedTables.includes(t));

            if (allVisibleSelected) {
                // Deselect: Remove all visible tables from the selection
                this.dbExplorer.selectedTables = this.dbExplorer.selectedTables.filter(t => !visibleTableNames.includes(t));
            } else {
                // Select: Add all visible tables that aren't already selected
                visibleTableNames.forEach(t => {
                    if (!this.dbExplorer.selectedTables.includes(t)) {
                        this.dbExplorer.selectedTables.push(t);
                    }
                });
            }

            // Sync legacy logic: If exactly one table is selected, load it into the single view
            if (this.dbExplorer.selectedTables.length === 1) {
                this.dbExplorer.selectedTable = this.dbExplorer.selectedTables[0];
                // Refresh view if active
                if (this.dbExplorer.activeTab === 'rows') {
                    this.dbExplorer.rows = [];
                    this.loadDbRows({ page: 1, itemsPerPage: this.dbExplorer.itemsPerPage });
                } else if (this.dbExplorer.activeTab === 'info') {
                    this.loadTableSchema();
                }
            } else {
                this.dbExplorer.selectedTable = null;
            }
        },
        async exportBatchTables() {
            if (this.dbExplorer.selectedTables.length === 0) return;
            
            this.dbExplorer.exporting = true;
            
            // CLI Defaults for Stability
            const DB_MAX_SIZE = 200 * 1024 * 1024; // 200MB
            const DB_MAX_ROWS = 1000000;           // 1 Million Rows

            // 1. Build the Job Queue (Hybrid Batching)
            const queue = [];
            let currentBatch = [];
            let currentBatchSize = 0;

            // Filter full database objects based on selection
            const selectedTableObjects = this.database.filter(t => 
                this.dbExplorer.selectedTables.includes(t.table)
            );

            selectedTableObjects.forEach(table => {
                const size = parseInt(table.size) || 0;
                const rows = parseInt(table.row_count) || 0;

                // Check if table is "Large" (Exceeds CLI thresholds)
                if ((size > DB_MAX_SIZE || rows > DB_MAX_ROWS) && rows > 0) {
                    
                    // If we have a pending batch of small tables, push it first
                    if (currentBatch.length > 0) {
                        queue.push({ type: 'batch', tables: [...currentBatch] });
                        currentBatch = [];
                        currentBatchSize = 0;
                    }

                    // Calculate splits for the large table
                    const partsBySize = Math.ceil(size / DB_MAX_SIZE);
                    const partsByRows = Math.ceil(rows / DB_MAX_ROWS);
                    const totalParts = Math.max(partsBySize, partsByRows);
                    const rowsPerPart = Math.ceil(rows / totalParts);

                    // Add a job for EACH part of this large table
                    for (let i = 1; i <= totalParts; i++) {
                        queue.push({
                            type: 'large_part',
                            table: table.table,
                            part: i,
                            total_parts: totalParts,
                            rows_per_part: rowsPerPart,
                            size: size / totalParts // Approximate for UI
                        });
                    }

                } else {
                    // "Small" Table - Add to current batch
                    if ((currentBatchSize + size > DB_MAX_SIZE) && currentBatch.length > 0) {
                        queue.push({ type: 'batch', tables: [...currentBatch] });
                        currentBatch = [];
                        currentBatchSize = 0;
                    }
                    currentBatch.push(table.table);
                    currentBatchSize += size;
                }
            });

            // Add any remaining small tables
            if (currentBatch.length > 0) {
                queue.push({ type: 'batch', tables: [...currentBatch] });
            }

            // 2. Process Queue Sequentially
            try {
                for (let i = 0; i < queue.length; i++) {
                    const job = queue[i];
                    
                    // Update UI Status
                    if (job.type === 'batch') {
                        this.snackbar.message = `Exporting batch ${i + 1}/${queue.length} (${job.tables.length} tables)...`;
                    } else {
                        this.snackbar.message = `Exporting ${job.table} (Part ${job.part}/${job.total_parts})...`;
                    }
                    this.snackbar.show = true;

                    let downloadUrl = "";

                    if (job.type === 'batch') {
                        // Batch API Call
                        const response = await axios.post(`${this.api_root}export-database-batch`, {
                            backup_token: this.backup_token,
                            token: this.api_token,
                            tables: job.tables
                        });
                        downloadUrl = response.data;
                    } else {
                        // Large Table Part API Call
                        const response = await axios.post(`${this.api_root}export/database/${job.table}`, {
                            token: this.api_token,
                            backup_token: this.backup_token,
                            parts: job.part,
                            rows_per_part: job.rows_per_part
                        });
                        downloadUrl = response.data;
                    }

                    if (downloadUrl) {
                        this.triggerDownload(downloadUrl);
                        
                        // IMPORTANT: Wait to prevent browser throttling downloads
                        await new Promise(resolve => setTimeout(resolve, 1500)); 
                    } else {
                        throw new Error("Server returned no URL for export.");
                    }
                }
                
                this.snackbar.message = "Database export complete.";

            } catch (error) {
                console.error(error);
                const msg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : "Export failed. Check console.";
                this.snackbar.message = msg;
                this.snackbar.show = true;
            } finally {
                this.dbExplorer.exporting = false;
            }
        },
        triggerDownload(url) {
            const link = document.createElement('a');
            link.href = url;
            
            // Clean up filename (remove .txt extension added for security)
            let fileName = url.substring(url.lastIndexOf('/') + 1);
            if (fileName.endsWith('.sql.txt')) {
                fileName = fileName.replace('.sql.txt', '.sql');
            }
            
            link.setAttribute('download', fileName);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        async loadTableSchema() {
            this.dbExplorer.loading = true;
            try {
                const response = await axios.get(`${this.api_root}database/schema`, {
                    params: {
                        token: this.api_token,
                        table: this.dbExplorer.selectedTable
                    }
                });
                this.dbExplorer.schema = response.data.structure;
                this.dbExplorer.tableStatus = response.data.status;
            } catch (error) {
                this.snackbar.message = "Failed to load table schema.";
                this.snackbar.show = true;
            } finally {
                this.dbExplorer.loading = false;
            }
        },
        async exportSingleTable() {
            if (!this.dbExplorer.selectedTable) return;
            
            this.dbExplorer.exporting = true;
            this.snackbar.message = `Generating export for ${this.dbExplorer.selectedTable}...`;
            this.snackbar.show = true;

            try {
                const response = await axios.post(`${this.api_root}export/database/${this.dbExplorer.selectedTable}`, {
                    token: this.api_token, 
                    backup_token: this.backup_token
                });

                const downloadUrl = response.data;

                if (downloadUrl) {
                    // Trigger download
                    const link = document.createElement('a');
                    link.href = downloadUrl;
                    link.setAttribute('download', ''); 
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    this.snackbar.message = "Export started.";
                } else {
                    throw new Error("No URL returned");
                }

            } catch (error) {
                console.error(error);
                this.snackbar.message = "Export failed. Check console for details.";
                this.snackbar.show = true;
            } finally {
                this.dbExplorer.exporting = false;
            }
        },
        async loadDbRows({ page, itemsPerPage, sortBy }) {
            if (!this.dbExplorer.selectedTable) return;
            
            this.dbExplorer.page = page;
            this.dbExplorer.sortBy = sortBy; 

            this.dbExplorer.loading = true;
            
            let orderby = null;
            let order = 'ASC';
            
            if (sortBy && sortBy.length > 0) {
                orderby = sortBy[0].key;
                order = sortBy[0].order === 'desc' ? 'DESC' : 'ASC';
            }

            try {
                const response = await axios.get(`${this.api_root}database/rows`, {
                    params: {
                        token: this.api_token,
                        table: this.dbExplorer.selectedTable,
                        page: page,
                        limit: itemsPerPage,
                        orderby: orderby, 
                        order: order      
                    }
                });
                
                this.dbExplorer.rows = response.data.rows || []; // Default to empty array if null
                this.dbExplorer.totalItems = response.data.total || 0; 
                
                if (this.dbExplorer.rows.length > 0) {
                    const keys = Object.keys(this.dbExplorer.rows[0]);
                    this.dbExplorer.headers = keys.map(key => ({
                        title: key,
                        key: key,
                        sortable: true
                    }));
                } else if (this.dbExplorer.headers.length === 0) {
                    this.dbExplorer.headers = [];
                }
                
            } catch (error) {
                this.snackbar.message = "Failed to load table data: " + error.message;
                this.snackbar.show = true;
                // Reset to avoid stuck loading state
                this.dbExplorer.totalItems = 0; 
            } finally {
                this.dbExplorer.loading = false;
            }
        },
        // Check if column is large text based on schema
        isTextarea(colName) {
            const colDef = this.dbExplorer.schema.find(c => c.Field === colName);
            if (!colDef) return false;
            const type = colDef.Type.toLowerCase();
            return type.includes('text') || type.includes('blob') || type.includes('json');
        },

        // Helper to show column type in UI hint
        getColumnType(colName) {
            const colDef = this.dbExplorer.schema.find(c => c.Field === colName);
            return colDef ? colDef.Type : '';
        },

        // Handle click event from v-data-table-server
        handleRowClick(event, { item }) {
            // Vuetify 3 passes an internal item wrapper. Access .raw to get the data object.
            const rowData = item.raw || item; 

            // If schema isn't loaded, we can't safely edit (need to know Primary Keys)
            if (this.dbExplorer.schema.length === 0) {
                this.loadTableSchema().then(() => {
                    this.openEditDialog(rowData);
                });
            } else {
                this.openEditDialog(rowData);
            }
        },
        openEditDialog(item) {
            // Deep copy to avoid modifying the table view directly before save
            this.editDialog.item = JSON.parse(JSON.stringify(item));
            this.editDialog.original = JSON.parse(JSON.stringify(item));
            this.editDialog.mode = 'edit';
            this.editDialog.show = true;
        },
        async saveRow() {
            this.editDialog.loading = true;

            if (this.editDialog.mode === 'create') {
                try {
                    const response = await axios.post(`${this.api_root}database/row/create`, {
                        token: this.api_token,
                        table: this.dbExplorer.selectedTable,
                        data: this.editDialog.item
                    });
                    
                    if (response.data.success) {
                        this.snackbar.message = "Row created successfully.";
                        this.snackbar.show = true;
                        this.editDialog.show = false;
                        // Reload rows
                        await this.loadDbRows({ 
                            page: this.dbExplorer.page || 1, 
                            itemsPerPage: this.dbExplorer.itemsPerPage,
                            sortBy: this.dbExplorer.sortBy || []
                        });
                    }
                } catch (error) {
                    console.error(error);
                    const msg = error.response && error.response.data && error.response.data.message 
                        ? error.response.data.message 
                        : "Failed to create row.";
                    this.snackbar.message = msg;
                    this.snackbar.show = true;
                } finally {
                    this.editDialog.loading = false;
                }
                return; // Exit function
            }

            // 1. Identify Primary Keys for the WHERE clause
            let primaryKeys = this.dbExplorer.schema.filter(col => col.Key === 'PRI');
            let whereClause = {};

            if (primaryKeys.length > 0) {
                primaryKeys.forEach(pk => {
                    // Only add if the key exists in the data
                    if (this.editDialog.original[pk.Field] !== undefined) {
                        whereClause[pk.Field] = this.editDialog.original[pk.Field];
                    }
                });
            }
            
            // Fallback: If no PKs were found or mapped (e.g. schema mismatch), use the full original row
            if (Object.keys(whereClause).length === 0) {
                whereClause = this.editDialog.original;
            }

            try {
                const response = await axios.post(`${this.api_root}database/row`, {
                    token: this.api_token,
                    table: this.dbExplorer.selectedTable,
                    data: this.editDialog.item,
                    where: whereClause
                });

                if (response.data.success) {
                    this.snackbar.message = "Row updated successfully.";
                    this.snackbar.show = true;
                    this.editDialog.show = false;
                    
                    await this.loadDbRows({ 
                        page: this.dbExplorer.page || 1, 
                        itemsPerPage: this.dbExplorer.itemsPerPage,
                        sortBy: this.dbExplorer.sortBy || []
                    });
                }
            } catch (error) {
                console.error(error);
                const msg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : "Failed to update row.";
                this.snackbar.message = msg;
                this.snackbar.show = true;
            } finally {
                this.editDialog.loading = false;
            }
        },
        async deleteRow() {
            if (!confirm("Are you sure you want to delete this row? This action cannot be undone.")) {
                return;
            }

            this.editDialog.loading = true;

            // 1. Identify Primary Keys for the WHERE clause
            let primaryKeys = this.dbExplorer.schema.filter(col => col.Key === 'PRI');
            let whereClause = {};

            if (primaryKeys.length > 0) {
                primaryKeys.forEach(pk => {
                    if (this.editDialog.original[pk.Field] !== undefined) {
                        whereClause[pk.Field] = this.editDialog.original[pk.Field];
                    }
                });
            }
            
            // Fallback: If no PKs were found or mapped, use the full original row
            if (Object.keys(whereClause).length === 0) {
                whereClause = this.editDialog.original;
            }

            try {
                const response = await axios.post(`${this.api_root}database/row/delete`, {
                    token: this.api_token,
                    table: this.dbExplorer.selectedTable,
                    where: whereClause
                });

                if (response.data.success) {
                    this.snackbar.message = "Row deleted successfully.";
                    this.snackbar.show = true;
                    this.editDialog.show = false;
                    
                    // Reload rows
                    await this.loadDbRows({ 
                        page: this.dbExplorer.page || 1, 
                        itemsPerPage: this.dbExplorer.itemsPerPage,
                        sortBy: this.dbExplorer.sortBy || []
                    });
                }
            } catch (error) {
                console.error(error);
                const msg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : "Failed to delete row.";
                this.snackbar.message = msg;
                this.snackbar.show = true;
            } finally {
                this.editDialog.loading = false;
            }
        },
        openCreateDialog() {
            // Create an empty item based on current headers
            const newItem = {};
            this.dbExplorer.headers.forEach(header => {
                // Initialize as empty string so v-model works
                newItem[header.key] = ""; 
            });

            this.editDialog.item = newItem;
            this.editDialog.mode = 'create'; // Set mode
            this.editDialog.show = true;
        },
        enableEditMode() {
            // Determine plain text content. 
            // Note: preview_content might contain HTML from PrismJS, so we use raw_content.
            // We need to ensure we captured raw_content during selectFile. 
            this.explorer.edit_content = this.explorer.raw_content; 
            this.explorer.is_editing = true;
        },

        cancelEdit() {
            this.explorer.is_editing = false;
            this.explorer.edit_content = '';
        },

        async saveFile() {
            if (!this.explorer.selected_node) return;
            
            this.explorer.saving_file = true;
            
            try {
                const response = await axios.post(`${this.api_root}file/save`, {
                    token: this.api_token,
                    file: this.explorer.selected_node.id,
                    content: this.explorer.edit_content
                });

                if (response.data.success) {
                    this.snackbar.message = "File saved successfully.";
                    this.snackbar.show = true;
                    this.explorer.is_editing = false;
                    
                    // Reload the preview to show changes and re-highlight syntax
                    // We can just re-trigger selectFile logic essentially
                    this.selectFile([this.explorer.selected_node.id]); 
                }
            } catch (error) {
                console.error(error);
                const msg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : "Failed to save file.";
                this.snackbar.message = msg;
                this.snackbar.show = true;
            } finally {
                this.explorer.saving_file = false;
            }
        },
        openRenameDialog() {
            if (!this.explorer.selected_node) return;
            this.renameDialog.oldPath = this.explorer.selected_node.id;
            this.renameDialog.newName = this.explorer.selected_node.name;
            this.renameDialog.show = true;
        },

        async performRename() {
            if (!this.renameDialog.newName || this.renameDialog.newName === this.explorer.selected_node.name) {
                this.renameDialog.show = false;
                return;
            }

            this.renameDialog.loading = true;

            try {
                const response = await axios.post(`${this.api_root}file/rename`, {
                    token: this.api_token,
                    old_path: this.renameDialog.oldPath,
                    new_name: this.renameDialog.newName
                });

                if (response.data.success) {
                    this.snackbar.message = "Renamed successfully.";
                    this.snackbar.show = true;
                    this.renameDialog.show = false;

                    // Update the UI without reloading page
                    this.updateTreeAfterRename(this.renameDialog.oldPath, this.renameDialog.newName);
                }
            } catch (error) {
                console.error(error);
                const msg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : "Rename failed.";
                this.snackbar.message = msg;
                this.snackbar.show = true;
            } finally {
                this.renameDialog.loading = false;
            }
        },

        updateTreeAfterRename(oldPath, newName) {
            // 1. Calculate the new ID (Path)
            const pathParts = oldPath.split('/');
            pathParts.pop(); // Remove old filename
            pathParts.push(newName);
            const newPath = pathParts.join('/');

            // 2. Update the raw file list
            // We need to update the renamed file AND (if it's a folder) all children paths
            this.explorer.raw_file_list.forEach(file => {
                if (file.name === oldPath) {
                    // Rename the exact file/folder
                    file.name = newPath;
                } else if (file.name.startsWith(oldPath + '/')) {
                    // Rename children (if it was a folder)
                    file.name = file.name.replace(oldPath + '/', newPath + '/');
                }
            });

            // 3. Re-sort the raw list to keep folders at top/alphabetical
            this.explorer.raw_file_list = this.sortFileList(this.explorer.raw_file_list);

            // 4. Rebuild the visual tree
            this.explorer.items = this.buildInitialTree(this.explorer.raw_file_list);

            // 5. Re-select the node with the new name so the UI doesn't lose focus
            // We need to wait for Vue to re-render the tree
            this.$nextTick(() => {
                // We use the 'active' prop on treeview to select
                this.explorer.activated = [newPath];
                
                // Manually trigger the select logic to update the details panel
                // (We need to find the new node object in the rebuilt tree)
                this.selectFile([newPath]); 
            });
        },
        async regenerateToken() {
            if (!confirm("Are you sure you want to regenerate the token? This will invalidate any existing CLI connections.")) {
                return;
            }
            this.regenerating_token = true;
            try {
                // Pass the current token in the POST body for authorization
                const response = await axios.post(`${this.api_root}regenerate-token`, { token: this.api_token });
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
            this.$vuetify.theme.change( newTheme );
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
                    const response = await axios.post( `${this.api_root}stream-file`, postData, { responseType: 'blob' });
                    this.explorer.preview_content = URL.createObjectURL(response.data);
                } catch (error) {
                    this.explorer.preview_content = 'Error loading image.';
                    this.explorer.preview_type = 'error';
                } finally {
                    this.explorer.loading_preview = false;
                }
            } else {
                this.explorer.preview_type = 'code';
                this.explorer.is_editing = false; 

                try {
                    if (item.size > 1024 * 500) {
                        throw new Error('File is too large to preview.');
                    }
                    
                    const response = await axios.post(`${this.api_root}stream-file`, postData );
                    
                    // Convert object to string if JSON
                    let content = (typeof response.data === 'object' && response.data !== null) ? JSON.stringify(response.data, null, 2) : response.data;

                    // -- STORE RAW CONTENT FOR EDITING --
                    this.explorer.raw_content = content; 

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

            if (node.children) {
                this.downloadFolder(node);
                return;
            }

            this.snackbar.message = `Preparing download for ${node.name}...`;
            this.snackbar.show = true;

            const postData = {
                token: this.api_token,
                file: node.id
            };

            try {
                const response = await axios.post( `${this.api_root}stream-file`, postData, { responseType: 'blob' } );

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
        async downloadFolder(node) {
            this.is_folder_downloading = true;
            this.loading_message = `Analyzing folder "${node.name}"...`;
            
            // Constant name for the entire loop so PHP appends to one file
            const timestamp = Math.floor(Date.now() / 1000);
            const safeName = node.name.replace(/[^a-z0-9]/gi, '_').toLowerCase();
            const archiveName = `${safeName}-${timestamp}.zip`;

            // Thresholds
            const MAX_CHUNK_SIZE = 100 * 1024 * 1024; // 100 MB
            const MAX_CHUNK_FILES = 500;              // 500 Files
            const LARGE_FILE_ISOLATION = 20 * 1024 * 1024; // 20 MB

            try {
                const folderPrefix = node.id + '/';
                const sourceFiles = this.explorer.raw_file_list
                    .filter(f => f.type === 'file' && f.name.startsWith(folderPrefix));

                if (sourceFiles.length === 0) throw new Error("Folder appears to be empty.");

                // Smart Chunking
                const chunks = [];
                let currentChunk = [];
                let currentChunkSize = 0;

                sourceFiles.forEach(file => {
                    const fileSize = parseInt(file.size) || 0;
                    
                    // Logic: Isolate large files to prevent timeouts, 
                    // or push batch if size/count limit reached.
                    const isLarge = fileSize > LARGE_FILE_ISOLATION;
                    const isFull = (currentChunkSize + fileSize > MAX_CHUNK_SIZE) || (currentChunk.length >= MAX_CHUNK_FILES);

                    if ((isFull || isLarge) && currentChunk.length > 0) {
                        chunks.push(currentChunk);
                        currentChunk = [];
                        currentChunkSize = 0;
                    }

                    currentChunk.push({ name: file.name });
                    currentChunkSize += fileSize;

                    // If this was a large file, force a push immediately so it processes alone
                    if (isLarge) {
                        chunks.push(currentChunk);
                        currentChunk = [];
                        currentChunkSize = 0;
                    }
                });
                
                if (currentChunk.length > 0) {
                    chunks.push(currentChunk);
                }

                // Sequential Upload Loop (Appends to same file on server)
                let processedChunks = 0;
                let zipUrl = "";
                const totalChunks = chunks.length;

                for (const chunk of chunks) {
                    processedChunks++;
                    this.loading_message = `Archiving ${node.name} (Batch ${processedChunks}/${totalChunks})...`;
                    
                    const response = await axios.post( `${this.api_root}zip-sync-files`, {
                        token: this.api_token,
                        backup_token: this.backup_token,
                        files: chunk,
                        archive_name: archiveName // Sending same name appends to the file
                    });

                    if (response.data && response.data.error) throw new Error(response.data.message);
                    
                    zipUrl = response.data;
                }

                // Trigger Download
                this.loading_message = "Download starting...";
                const link = document.createElement('a');
                link.href = zipUrl;
                link.setAttribute('download', archiveName);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                // --- FIX: Correct Size Reporting ---
                // Default to sum of files (uncompressed) just in case
                this.zip_cleanup.size = node.stats ? node.stats.totalSize : 0; 

                try {
                    // Ask the server for the actual Content-Length of the generated zip
                    const headResponse = await axios.head(zipUrl);
                    if (headResponse.headers['content-length']) {
                        this.zip_cleanup.size = parseInt(headResponse.headers['content-length']);
                    }
                } catch (e) {
                    console.log("Could not fetch actual zip size, using estimated size.");
                }
                // -----------------------------------

                // Show Cleanup Dialog
                this.zip_cleanup.file_name = archiveName;
                this.zip_cleanup.folder_name = node.name;
                // Size is already set above
                this.zip_cleanup.show = true;

            } catch (error) {
                console.error("Folder download failed:", error);
                this.snackbar.message = `Failed to download folder: ${error.message}`;
                this.snackbar.show = true;
            } finally {
                this.is_folder_downloading = false;
                this.loading_message = "Backup in progress...";
            }
        },
        async performZipCleanup() {
            this.zip_cleanup.loading = true;
            try {
                await axios.post( `${this.api_root}cleanup-file`, {
                    token: this.api_token,
                    backup_token: this.backup_token,
                    file_name: this.zip_cleanup.file_name
                });
                this.snackbar.message = "Temporary zip file deleted.";
                this.snackbar.show = true;
                this.zip_cleanup.show = false;
                await this.fetchBackupSize(); // Refresh stats
            } catch (error) {
                this.snackbar.message = "Failed to delete temporary file.";
                this.snackbar.show = true;
            } finally {
                this.zip_cleanup.loading = false;
            }
        },
        areAllTablesSelected() {
            if (this.filteredDbTables.length === 0) return false;
            // Check if every currently filtered table is in the selected list
            return this.filteredDbTables.every(t => this.dbExplorer.selectedTables.includes(t.table));
        },
        isSelectionIndeterminate() {
            if (this.filteredDbTables.length === 0) return false;
            // Count how many visible tables are selected
            const selectedCount = this.filteredDbTables.filter(t => this.dbExplorer.selectedTables.includes(t.table)).length;
            // Return true if some, but not all, are selected
            return selectedCount > 0 && selectedCount < this.filteredDbTables.length;
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
                const dbResponse = await axios.get(`${this.api_root}database`, { 
                    params: { token: this.api_token } 
                });
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
                await axios.post(`${this.api_root}regenerate-manifest`, { token: this.api_token, backup_token: this.backup_token, step: 'initiate' });
                this.scan_progress.status = 'scanning';
                let scan_complete = false;
                while (!scan_complete) {
                    const scanResponse = await axios.post(`${this.api_root}regenerate-manifest`, { token: this.api_token, backup_token: this.backup_token, step: 'scan', exclude_files: this.options.exclude_files });
                    if (scanResponse.data.status === 'scan_complete') {
                        scan_complete = true;
                    }
                    this.scan_progress.total = scanResponse.data.total_dirs;
                    this.scan_progress.scanned = scanResponse.data.scanned_dirs;
                }

                this.scan_progress.status = 'chunking';
                const chunkifyResponse = await axios.post(`${this.api_root}regenerate-manifest`, { token: this.api_token, backup_token: this.backup_token, step: 'chunkify' });
                const total_chunks = chunkifyResponse.data.total_chunks;
                this.manifest_progress.total = total_chunks;

                for (let i = 1; i <= total_chunks; i++) {
                    await axios.post(`${this.api_root}regenerate-manifest`, { token: this.api_token, backup_token: this.backup_token, step: 'process_chunk', chunk: i });
                    this.manifest_progress.fetched = i;
                }

                const finalizeResponse = await axios.post(`${this.api_root}regenerate-manifest`, { token: this.api_token, backup_token: this.backup_token, step: 'finalize' });
                return finalizeResponse.data;
            } catch (error) {
                console.error("Manifest generation failed:", error);
                throw new Error("Could not regenerate the file manifest. " + error.message);
            }
        },
        async copyText( value ) {
            try {
                // 1. Try the modern Clipboard API (No selection/focus needed)
                await navigator.clipboard.writeText(value);
                this.snackbar.message = "Copied to clipboard.";
                this.snackbar.show = true;
            } catch (err) {
                // 2. Fallback for non-secure contexts (http) or older browsers
                const textArea = document.createElement("textarea");
                textArea.value = value;
                
                // Position fixed ensures focusing doesn't scroll the page
                textArea.style.position = "fixed";
                textArea.style.left = "-9999px";
                textArea.style.top = "0";
                document.body.appendChild(textArea);
                
                textArea.focus();
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    this.snackbar.message = "Copied to clipboard.";
                    this.snackbar.show = true;
                } catch (err) {
                    console.error('Copy failed', err);
                    this.snackbar.message = "Failed to copy.";
                    this.snackbar.show = true;
                }
                
                document.body.removeChild(textArea);
            }
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
                info: `disembark info ${this.home_url}`,
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
            const separator = this.api_root.includes('?') ? '&' : '?';
            return `${this.api_root}stream-file${separator}token=${encodeURIComponent(this.api_token)}&file=${encodeURIComponent(this.explorer.selected_node.id)}`;
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
        filteredDbTables() {
            if (!this.database) return [];
            
            // Filter
            let tables = this.database;
            if (this.dbExplorer.search) {
                const lowerSearch = this.dbExplorer.search.toLowerCase();
                tables = tables.filter(t => t.table.toLowerCase().includes(lowerSearch));
            }

            // Sort Alphabetically
            return [...tables].sort((a, b) => a.table.localeCompare(b.table));
        },
        batchExportStats() {
            if (this.dbExplorer.selectedTables.length === 0) return { size: 0, rows: 0 };
            
            // Map selected names back to the full database objects to get stats
            return this.database
                .filter(t => this.dbExplorer.selectedTables.includes(t.table))
                .reduce((acc, curr) => {
                    acc.size += parseInt(curr.size) || 0;
                    acc.rows += parseInt(curr.row_count) || 0;
                    return acc;
                }, { size: 0, rows: 0 });
        },
        areAllTablesSelected() {
            if (this.filteredDbTables.length === 0) return false;
            // Check if every currently filtered table is in the selected list
            return this.filteredDbTables.every(t => this.dbExplorer.selectedTables.includes(t.table));
        },
        isSelectionIndeterminate() {
            if (this.filteredDbTables.length === 0) return false;
            // Count how many visible tables are selected
            const selectedCount = this.filteredDbTables.filter(t => this.dbExplorer.selectedTables.includes(t.table)).length;
            // Return true if some, but not all, are selected
            return selectedCount > 0 && selectedCount < this.filteredDbTables.length;
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
            this.$vuetify.theme.change( storedTheme );
            if (storedTheme === 'dark') {
                document.body.classList.add('disembark-dark-mode');
            }
        }
        this.fetchBackupSize();
    }
}).use(vuetify).mount('#app');
</script>