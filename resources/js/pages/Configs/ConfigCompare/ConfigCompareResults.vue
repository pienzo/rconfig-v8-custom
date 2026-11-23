<script setup>
import Loading from '@/pages/Shared/Loaders/Loading.vue';
import CompareDiffEditorComponent from './CompareDiffEditorComponent.vue';
import { useCompareResults } from './useCompareResults';

const props = defineProps({
  leftSelectedId: Array,
  rightSelectedId: Array
});

const { isLoadingComponent, comparisonError, configResultsLeft, configResultsRight } = useCompareResults(props);
</script>

<template>
	<div
		v-if="isLoadingComponent"
		class="flex items-center justify-center h-full mt-48"
	>
		<Loading :text="'Running comparison'" />
	</div>

	<div v-if="!isLoadingComponent && comparisonError" role="alert" class="m-6 rounded border p-4 text-sm text-red-500">{{ comparisonError }}</div>

	<CompareDiffEditorComponent
		v-if="!isLoadingComponent && !comparisonError"
		:config-results-right="configResultsRight"
		:config-results-left="configResultsLeft"
		:left-selected-id="leftSelectedId"
		:right-selected-id="rightSelectedId"
	/>
</template>
