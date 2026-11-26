<script setup>
import * as monaco from 'monaco-editor';
import useCodeEditor from '@/composables/codeEditorFunctions';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Copy, Download, SunMoon, Hash, FoldVertical } from 'lucide-vue-next';
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  leftSelectedId: Array,
  rightSelectedId: Array,
  configResultsLeft: Object,
  configResultsRight: Object
});

const toggleStateMultiple = ref([]); //'dark', 'lineNumbers', 'hideUnchanged', 'minimap', 'stickyscroll'
const { checkDarkModeIsSet, checkHideUnchangedIsSet, checkLineNumbersIsSet, checkMiniMapIsSet, checkStickyScrollIsSet, copyContent, downloadCompare, initDiffEditor, toggleEditorDarkMode, toggleEditorLineNumbers, toggleHideUnchanged, toggleEditorMinimap, toggleStickyScroll } = useCodeEditor(monaco);

onMounted(() => {
  initializeToggleStates();
  initCodeEditor();
});

function initializeToggleStates() {
  if (checkDarkModeIsSet()) toggleStateMultiple.value.push('dark');
  if (checkLineNumbersIsSet()) toggleStateMultiple.value.push('lineNumbers');
  if (checkHideUnchangedIsSet()) toggleStateMultiple.value.push('hideUnchanged');
  //   if (checkMiniMapIsSet()) toggleStateMultiple.value.push('minimap');
  //   if (checkStickyScrollIsSet()) toggleStateMultiple.value.push('stickyscroll');
}

let diffEditor = null;
let originalModel = null;
let modifiedModel = null;

function comparedSources() {
  const leftText = props.configResultsLeft?.filecontent ?? '';
  const rightText = props.configResultsRight?.filecontent ?? '';
  return '=== ORIGINAL CONFIGURATION ===\n' + leftText + '\n\n=== MODIFIED CONFIGURATION ===\n' + rightText;
}

function downloadComparedSources() {
  downloadCompare(comparedSources(), `rconfig-compare-${props.leftSelectedId?.[0] ?? 'left'}-${props.rightSelectedId?.[0] ?? 'right'}.txt`, 'compare');
}

onUnmounted(() => {
  diffEditor?.dispose();
  originalModel?.dispose();
  modifiedModel?.dispose();
});

function initCodeEditor() {
  originalModel = monaco.editor.createModel(props.configResultsLeft.filecontent, 'text/plain');
  modifiedModel = monaco.editor.createModel(props.configResultsRight.filecontent, 'text/plain');

  diffEditor = initDiffEditor('code-editor__code-pre', 'rconfig');

  if (diffEditor) {
    // Keep side-by-side comparison at normal desktop panel widths; Monaco
    // retains its inline fallback for genuinely narrow viewports.
    diffEditor.updateOptions({ renderSideBySide: true, renderSideBySideInlineBreakpoint: 600 });
    diffEditor.setModel({
      original: originalModel,
      modified: modifiedModel
    });
  }
}
</script>

<template>
	<div class="flex flex-col w-full min-w-0">
		<div class="flex items-center p-2">
			<div class="flex items-center gap-2"></div>

			<!-- LEFT BUTTONS -->
			<div>
				<Button
					variant="ghost"
					title="Copy compared configuration sources" aria-label="Copy compared configuration sources"
					@click="copyContent(comparedSources(), 'compare')"
				>
					<Copy class="w-4 h-4" aria-hidden="true" />
				</Button>

				<Button
					variant="ghost"
					class="ml-1"
					title="Export compared configuration sources" aria-label="Export compared configuration sources"
					@click="downloadComparedSources"
				>
					<Download class="w-4 h-4" aria-hidden="true" />
				</Button>

				<!-- <Button
            variant="ghost"
            class="ml-1"
            @click="showConfigFullScreen()"
            title="Full Screen">
            <Icon icon="ant-design:fullscreen-outlined"></Icon>
          </Button> -->
			</div>
			<!-- LEFT BUTTONS -->

			<Separator
				orientation="vertical"
				class="relative w-px h-6 mx-4 shrink-0 bg-border"
			/>

			<!-- RIGHT BUTTONS -->
			<div class="flex items-center gap-2 ml-auto">
				<ToggleGroup
					v-model="toggleStateMultiple"
					type="multiple"
				>
					<ToggleGroupItem
						value="dark"
						title="Toggle editor dark mode" aria-label="Toggle editor dark mode"
						@click="toggleEditorDarkMode()"
					>
						<SunMoon class="w-4 h-4" aria-hidden="true" />
					</ToggleGroupItem>
					<ToggleGroupItem
						value="lineNumbers"
						title="Line Numbers" aria-label="Toggle line numbers"
						@click="toggleEditorLineNumbers()"
					>
						<Hash class="w-4 h-4" aria-hidden="true" />
					</ToggleGroupItem>
					<ToggleGroupItem
						value="hideUnchanged"
						title="Show only differences" aria-label="Show only differences"
						@click="toggleHideUnchanged()"
					>
						<FoldVertical class="w-4 h-4" aria-hidden="true" />
					</ToggleGroupItem>
					<!-- <ToggleGroupItem
            value="minimap"
            title="Minimap"
            @click="toggleEditorMinimap()">
            <Icon
              icon="tabler:map-2"
              class="w-4 h-4" />
          </ToggleGroupItem> -->
				</ToggleGroup>
			</div>
			<!-- RIGHT BUTTONS -->

			<Separator
				orientation="vertical"
				class="relative w-px h-6 mx-4 shrink-0 bg-border"
			/>
			<span>CONFIG</span>
		</div>
		<Separator class="relative w-full h-px shrink-0 bg-border"></Separator>
	</div>
	<div
		id="code-editor__code-pre"
		class="code-editor__code-pre w-full min-w-0"
		style="height: calc(80vh)"
	></div>
	<!-- EDITOR -->
	<!-- <pre style="white-space: pre-wrap"> {{ configResultsLeft }}</pre>
  <pre style="white-space: pre-wrap"> {{ configResultsRight }}</pre> -->
	<!-- EDITOR -->
</template>

<style scoped>
/* "N hidden lines" bars from "Show only differences" - make them stand out from config text. */
.code-editor__code-pre :deep(.monaco-editor .diff-hidden-lines .center) {
	background: #dbeafe;
	color: #1e40af;
	font-weight: 600;
	border-top: 1px solid #93c5fd;
	border-bottom: 1px solid #93c5fd;
}

.code-editor__code-pre :deep(.monaco-editor.vs-dark .diff-hidden-lines .center) {
	background: rgba(59, 130, 246, 0.18);
	color: #93c5fd;
	border-top-color: rgba(96, 165, 250, 0.45);
	border-bottom-color: rgba(96, 165, 250, 0.45);
}
</style>
