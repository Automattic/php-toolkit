import { store, getContext, getElement, getServerContext } from '@wordpress/interactivity';
const { dispatch } = window.wp.data;

const apiFetch = window.wp.apiFetch;

const { state, actions } = store('staticFiles', {
	state: {
		get isLocalDirectory() {
			return state.dataSourceType === 'local_directory';
		},
		get isGitRepo() {
			return state.dataSourceType === 'git_repo';
		},
		get isGithubRepo() {
			return state.dataSourceType === 'github_repository';
		}
	},
    callbacks: {
        bindSelectedBranch(e) {
            const { ref } = getElement();
            ref.value = state.selectedBranch;
        },
    },
    actions: {
        updateGitRepo(e) {
            state.gitRepo = e.target.value;
        },
        updateSelectedBranch(e) {
            state.selectedBranch = e.target.value;
        },
        updateSubdirectory(e) {
            state.subdirectory = e.target.value;
        },
        updateLocalDirectory(e) {
            state.localDirectory = e.target.value;
        },
        updateDataSourceType(e) {
			state.dataSourceType = e.target.value;
			console.log(state.dataSourceType);
			
			// If GitHub repository is selected, fetch repos if we haven't already
			if (state.dataSourceType === 'github_repository' && state.githubRepos.length === 0) {
				actions.fetchGitHubRepos();
			}
        },
        async onGitRepoInputEnter(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                await actions.fetchBranches();
            }
        },
        async onFormEnter(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                await actions.saveSettings();
            }
        },
		async fetchBranches() {
            const response = await apiFetch({
                path: '/static-files-editor/v1/data-source/branches',
                method: 'POST',
                data: {
					gitRepo: state.gitRepo,
					provider: state.isGithubRepo ? 'github' : 'git',
                },
            });
            state.branches = response.refs;
            state.selectedBranch = response.default_branch;
        },
        async fetchFiles() {
            const response = await apiFetch({
                path: '/static-files-editor/v1/data-source/files',
                method: 'POST',
                data: {
                    gitRepo: state.gitRepo,
                    branch: state.selectedBranch,
					provider: state.isGithubRepo ? 'github' : 'git',
                },
            });
            state.files = response.files;
        },
        async saveSettings() {
            try {
                const response = await apiFetch({
                    path: '/static-files-editor/v1/save-settings',
                    method: 'POST',
                    data: {
                        gitRepo: state.gitRepo,
                        selectedBranch: state.selectedBranch,
                        subdirectory: state.subdirectory,
                        localDirectory: state.localDirectory,
                        dataSourceType: state.dataSourceType,
                    },
                });
                state.notices.push({
                    type: 'notice-success notice is-dismissible',
                    message: 'Settings saved successfully!'
                });
            } catch (error) {
                state.notices.push({
                    type: 'notice-error notice is-dismissible',
                    message: 'Error saving settings. Please try again.'
                });
            }
        },
        async forcePull() {
            try {
                const response = await apiFetch({
                    path: '/static-files-editor/v1/data-source/sync',
                    method: 'POST',
                });
                state.notices.push({
                    type: 'notice-success notice is-dismissible',
                    message: 'Force pull completed!'
                });
            } catch (error) {
                state.notices.push({
                    type: 'notice-error notice is-dismissible',
                    message: 'Error force pulling. Please try again.'
                });
            }
        },
		async authorizeWithGitHub() {
			// @TODO: Configure via env variables
            const clientId = '';
            const redirectUri = '';
            const state = Math.random().toString(36).substring(2);
            
            // Store state in localStorage to verify when the callback returns
            localStorage.setItem('github_oauth_state', state);
            
            const scope = 'repo';
            const url =
                'https://github.com/login/oauth/authorize' +
                `?client_id=${clientId}` +
                `&redirect_uri=${encodeURIComponent(redirectUri)}` +
                `&scope=${encodeURIComponent(scope)}` +
                `&state=${encodeURIComponent(state)}`;

            // Open a popup
            const width = 600, height = 700;
            const left = (window.screen.width - width) / 2;
            const top = (window.screen.height - height) / 2;
            const popup = window.open(
                url,
                'AuthorizeWithGitHub',
                `width=${width},height=${height},left=${left},top=${top}`
            );
            
            // Listen for messages from the popup
			window.addEventListener('message', async (evt) => {
                if (evt?.data?.command !== 'auth-data') return;
                
                const authData = evt.data.data;
                if (!authData || !authData.access_token) {
                    state.notices.push({
                        type: 'notice-error notice is-dismissible',
                        message: 'GitHub authorization failed. Please try again.'
                    });
                    return;
                }
                
                // Store the token on the backend immediately
                try {
                    const response = await apiFetch({
                        path: '/static-files-editor/v1/github/store-token',
                        method: 'POST',
                        data: {
                            token: authData.access_token,
                        },
                    });
                    
                    // Update UI to show we're authorized
                    state.githubToken = true;
                    
                    state.notices.push({
                        type: 'notice-success notice is-dismissible',
                        message: 'Successfully authorized with GitHub! You can now fetch your repositories.'
                    });
                    
                    // Note: We don't fetch repositories automatically here
                    // The user will need to click the "Refresh Repositories" button
                } catch (error) {
                    state.notices.push({
                        type: 'notice-error notice is-dismissible',
                        message: 'Error storing GitHub token. Please try again.'
                    });
                }
            });
        },
        
        async fetchGitHubRepos() {
            try {
                const response = await apiFetch({
                    path: '/static-files-editor/v1/github/repos',
                    method: 'GET',
                });
                console.log(response);
                state.githubRepos = response;
            } catch (error) {
                state.notices.push({
                    type: 'notice-error notice is-dismissible',
                    message: 'Error fetching GitHub repositories. Please try again.'
                });
            }
        },
        
        async updateSelectedRepo(e) {
			state.gitRepo = e.target.value;
			state.branches = [];
			await actions.fetchBranches();
        },
        
    }
});