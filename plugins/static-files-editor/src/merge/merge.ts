import { diff_match_patch as DiffMatchPatch, Diff, patch_obj, DIFF_EQUAL, DIFF_DELETE, DIFF_INSERT } from 'diff-match-patch';
import { MergeResult, MergeException, InvalidMergeException, MergeConflict } from './types';

/**
 * Creates a diff between two strings using diff-match-patch
 */
export function createDiff(base: string, branch: string): Diff[] {
    const dmp = new DiffMatchPatch();
    const diff = dmp.diff_main(base, branch);
    dmp.diff_cleanupSemantic(diff);
    return diff;
}

/**
 * Creates chunks from a diff that can be used for merging
 */
export function createChunks(diff: Diff[]): Array<{
    base: string;
    inserted: string;
    deleted: boolean;
}> {
    const chunks: Array<{
        base: string;
        inserted: string;
        deleted: boolean;
    }> = [];
    
    let currentChunk = {
        base: '',
        inserted: '',
        deleted: false
    };

    for (const [operation, text] of diff) {
        switch (operation) {
            case DIFF_EQUAL:
                if (currentChunk.deleted || currentChunk.inserted) {
                    chunks.push({ ...currentChunk });
                    currentChunk = {
                        base: '',
                        inserted: '',
                        deleted: false
                    };
                }
                currentChunk.base = text;
                break;

            case DIFF_DELETE:
                currentChunk.deleted = true;
                currentChunk.base = text;
                break;

            case DIFF_INSERT:
                currentChunk.inserted = text;
                break;
        }
    }

    if (currentChunk.base || currentChunk.inserted) {
        chunks.push(currentChunk);
    }

    return chunks;
}

/**
 * Merges chunks from two branches into a single result
 */
export function mergeChunks(
    chunksA: ReturnType<typeof createChunks>,
    chunksB: ReturnType<typeof createChunks>
): MergeResult {
    const results: (string | MergeConflict)[] = [];
    const maxLength = Math.max(chunksA.length, chunksB.length);

    for (let i = 0; i < maxLength; i++) {
        const chunkA = chunksA[i] || { base: null, inserted: '', deleted: false };
        const chunkB = chunksB[i] || { base: null, inserted: '', deleted: false };

        // Handle conflicting insertions
        if (chunkA.inserted && chunkB.inserted && chunkA.inserted !== chunkB.inserted) {
            results.push(new MergeConflict(
                chunkA.inserted,
                chunkB.inserted,
                { message: 'Conflicting insertions' }
            ));
            continue;
        }

        // Handle null base values (chunks that only exist in one branch)
        if (chunkA.base === null || chunkB.base === null) {
            if (chunkA.base !== null) {
                results.push(chunkA.base + chunkA.inserted);
            } else if (chunkB.base !== null) {
                results.push(chunkB.base + chunkB.inserted);
            }
            continue;
        }

        // Handle mismatched base lines
        if (chunkA.base !== chunkB.base) {
            results.push(new MergeConflict(
                chunkA.base,
                chunkB.base,
                { message: 'Mismatched base lines' }
            ));
            continue;
        }

        // Handle deletions
        if (chunkA.deleted || chunkB.deleted) {
            if (chunkA.deleted && chunkB.deleted) {
                continue; // Both deleted the same content
            }

            const deletion = chunkA.deleted ? chunkA : chunkB;
            const nonDeletion = chunkA.deleted ? chunkB : chunkA;

            if (deletion.inserted) {
                if (nonDeletion.inserted) {
                    results.push(new MergeConflict(
                        deletion.inserted,
                        nonDeletion.inserted,
                        { message: 'Deletion with conflicting insertion' }
                    ));
                    continue;
                } else {
                    results.push(deletion.inserted);
                }
            }
            continue;
        }

        // Handle normal case (no conflicts)
        results.push(chunkA.base);
        const onlyInsertion = chunkA.inserted || chunkB.inserted;
        if (onlyInsertion) {
            results.push(onlyInsertion);
        }
    }

    return new MergeResult(results);
}

/**
 * Validates block markup using window.wp exports
 */
export function validateBlockMarkup(content: string): boolean {
    if (typeof window === 'undefined' || !window.wp) {
        // If running in a non-browser environment or WP is not available,
        // we can't validate - assume it's valid
        return true;
    }

    try {
        // Try to parse the content as blocks using whatever block parser is available
        // First try the modern parser
        if (window.wp.blocks?.parse) {
            const blocks = window.wp.blocks.parse(content);
            return Array.isArray(blocks) && blocks.length > 0;
        }
        
        // Fall back to legacy parser if available
        if (window.wp.blockSerializationDefaultParser?.parse) {
            const blocks = window.wp.blockSerializationDefaultParser.parse(content);
            return Array.isArray(blocks) && blocks.length > 0;
        }

        // If no parser is available, assume content is valid
        return true;
    } catch (error) {
        return false;
    }
}

/**
 * Performs a three-way merge between a base version and two branches
 */
export function threeWayMerge(base: string, branchA: string, branchB: string): MergeResult {
    try {
        // Create diffs between base and each branch
        const diffA = createDiff(base, branchA);
        const diffB = createDiff(base, branchB);

        // Create chunks from diffs
        const chunksA = createChunks(diffA);
        const chunksB = createChunks(diffB);

        // Merge chunks
        const mergedContent = mergeChunks(chunksA, chunksB);

        // Validate merged content
        if (!validateBlockMarkup(mergedContent.toString())) {
            return new MergeResult([
                new MergeConflict(
                    branchA,
                    branchB,
                    { message: 'Merged content contains invalid block markup' }
                )
            ]);
        }

        return mergedContent;
    } catch (error) {
        if (error instanceof MergeException) {
            return new MergeResult([
                new MergeConflict(
                    branchA,
                    branchB,
                    { message: error.message }
                )
            ]);
        }
        throw error;
    }
}
