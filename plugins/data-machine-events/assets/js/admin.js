jQuery(document).ready(function($) {
    let currentStep = 1;
    let maxSteps = 4;
    let editingModuleId = 0;
    let step2Loaded = false;
    let step3Loaded = false;

    // Modal functionality – both primary and empty-state buttons open the modal
    $('#chill-create-module, #chill-create-module-btn').on('click', function(e) {
        e.preventDefault();
        openModal();
    });

    $('.chill-edit-module').on('click', function(e) {
        e.preventDefault();
        const moduleId = $(this).data('module-id');
        loadModuleForEditing(moduleId);
    });

    $('.chill-modal-close, #chill-cancel-btn').on('click', function() {
        closeModal();
    });

    // Close modal when clicking outside
    $('#chill-module-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Step navigation
    $('#chill-next-btn').on('click', function() {
        if (validateStep(currentStep)) {
            if (currentStep === 1 && !step2Loaded) {
                loadDataSourceSettings(function(){ step2Loaded = true; });
            } else if (currentStep === 2 && !step3Loaded) {
                loadTaxonomyMappings(function(){ step3Loaded = true; });
            } else if (currentStep === 3) {
                populateReview();
            }
            
            if (currentStep < maxSteps) {
                nextStep();
            }
        }
    });

    $('#chill-prev-btn').on('click', function() {
        if (currentStep > 1) {
            prevStep();
        }
    });

    $('#chill-save-btn').on('click', function() {
        saveModule();
    });

    // Data source change handler
    $(document).on('change', '#data-source', function() {
        const dataSource = $(this).val();
        if (dataSource) {
            $('#chill-next-btn').prop('disabled', false);
        } else {
            $('#chill-next-btn').prop('disabled', true);
        }
    });

    function applySavedMappings(mappings) {
        if (!mappings) return;
        $.each(mappings, function(taxonomy, termId) {
            const selector = 'select[name="taxonomy_mappings[' + taxonomy + ']\"]';
            $(selector).val(termId);
        });
    }

    function openModal(title = 'Create Import Module') {
        $('#chill-modal-title').text(title);
        resetModal();
        $('#chill-module-modal').show();
    }

    function closeModal() {
        $('#chill-module-modal').hide();
        resetModal();
    }

    function resetModal() {
        currentStep = 1;
        editingModuleId = 0;
        step2Loaded = false;
        step3Loaded = false;
        $('#chill-module-form')[0].reset();
        $('.chill-step').hide();
        $('#step-1').show();
        updateButtons();
        $('#data-source-settings').empty();
        $('#taxonomy-mappings').empty();
        $('#module-review').empty();
    }

    function nextStep() {
        $('.chill-step').hide();
        currentStep++;
        $('#step-' + currentStep).show();
        updateButtons();
    }

    function prevStep() {
        $('.chill-step').hide();
        currentStep--;
        $('#step-' + currentStep).show();
        updateButtons();
    }

    function updateButtons() {
        if (currentStep === 1) {
            $('#chill-prev-btn').hide();
        } else {
            $('#chill-prev-btn').show();
        }

        if (currentStep === maxSteps) {
            $('#chill-next-btn').hide();
            $('#chill-save-btn').show();
        } else {
            $('#chill-next-btn').show();
            $('#chill-save-btn').hide();
        }
    }

    function validateStep(step) {
        let isValid = true;
        
        if (step === 1) {
            const moduleName = $('#module-name').val().trim();
            const dataSource = $('#data-source').val();
            
            if (!moduleName) {
                alert('Please enter a module name.');
                isValid = false;
            } else if (!dataSource) {
                alert('Please select a data source.');
                isValid = false;
            }
        }
        
        return isValid;
    }

    function loadDataSourceSettings(callback) {
        const dataSource = $('#data-source').val();
        
        $.post(ajaxurl, {
            action: 'chill_get_data_source_settings',
            data_source: dataSource,
            _wpnonce: $('#chill_module_nonce').val()
        }, function(response) {
            if (response.success) {
                $('#data-source-settings').html(response.data.html);
                // First give caller a chance to populate saved values
                if (typeof callback === 'function') {
                    callback();
                }
                // Now initialise conditional logic (runs visibility pass)
                initConditionalFields();
                // Trigger change on all parent select fields to force re-evaluation after values restored
                $('#data-source-settings select').trigger('change');
            } else {
                alert('Error loading data source settings: ' + response.data);
            }
        });
    }

    /**
     * Show/hide settings rows based on conditional data attributes
     */
    function initConditionalFields() {
        const rows = $('#data-source-settings .settings-field-row');

        function evaluateRow($row) {
            const condField = $row.data('cond-field');
            if (!condField) return; // always visible

            const condValue = $row.data('cond-value');
            const currentVal = $('#setting-' + condField).val();
            if (currentVal === condValue) {
                $row.show();
            } else {
                $row.hide();
            }
        }

        // Initial pass
        rows.each(function() {
            evaluateRow($(this));
        });

        // Bind change events on all parent fields (unique cond-field values)
        const parentFields = [];
        rows.each(function() {
            const field = $(this).data('cond-field');
            if (field && parentFields.indexOf(field) === -1) {
                parentFields.push(field);
            }
        });

        parentFields.forEach(function(parent) {
            $(document).on('change', '#setting-' + parent, function() {
                rows.each(function() {
                    evaluateRow($(this));
                });
            });
        });
    }

    function loadTaxonomyMappings(callback) {
        $.post(ajaxurl, {
            action: 'chill_get_taxonomy_mappings',
            _wpnonce: $('#chill_module_nonce').val()
        }, function(response) {
            if (response.success) {
                $('#taxonomy-mappings').html(response.data.html);
                if (typeof callback === 'function') {
                    callback();
                }
            } else {
                alert('Error loading taxonomy mappings: ' + response.data);
            }
        });
    }

    function populateReview() {
        const moduleName = $('#module-name').val();
        const dataSource = $('#data-source option:selected').text();
        const status = $('#module-status option:selected').text();
        const maxEvents = $('#max-events').val();
        
        let reviewHtml = '<h4>Module Configuration Summary</h4>';
        reviewHtml += '<table class="form-table">';
        reviewHtml += '<tr><th>Module Name:</th><td>' + moduleName + '</td></tr>';
        reviewHtml += '<tr><th>Data Source:</th><td>' + dataSource + '</td></tr>';
        reviewHtml += '<tr><th>Status:</th><td>' + status + '</td></tr>';
        reviewHtml += '<tr><th>Max Events:</th><td>' + maxEvents + '</td></tr>';
        
        // Show data source settings
        const settingsCount = $('#data-source-settings input, #data-source-settings select').length;
        reviewHtml += '<tr><th>Data Source Settings:</th><td>' + settingsCount + ' settings configured</td></tr>';
        
        // Show taxonomy mappings
        const mappings = [];
        $('#taxonomy-mappings select').each(function() {
            const field = $(this).attr('name').replace('taxonomy_mappings[', '').replace(']', '');
            const taxonomy = $(this).val();
            if (taxonomy && taxonomy !== 'skip') {
                mappings.push(field + ' → ' + taxonomy);
            }
        });
        reviewHtml += '<tr><th>Taxonomy Mappings:</th><td>' + (mappings.length > 0 ? mappings.join('<br>') : 'None') + '</td></tr>';
        
        reviewHtml += '</table>';
        
        $('#module-review').html(reviewHtml);
    }

    function saveModule() {
        const formData = new FormData($('#chill-module-form')[0]);
        formData.append('action', 'chill_save_module');
        formData.append('module_id', editingModuleId);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    closeModal();
                    location.reload(); // Refresh to show updated modules
                } else {
                    alert('Error saving module: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred while saving the module.');
            }
        });
    }

    function loadModuleForEditing(moduleId) {
        $.get(ChillEventsAdmin.ajax_url, {
            action: 'chill_load_module',
            module_id: moduleId,
            _wpnonce: ChillEventsAdmin.nonce_load_module
        }, function(response) {
            if (response.success) {
                const module = response.data;
                // Open modal first (this resets the form)
                openModal('Edit Import Module: ' + module.name);

                editingModuleId = moduleId;

                // Populate basic fields (Step 1 & 4)
                $('#module-name').val(module.name);
                $('#data-source').val(module.data_source);
                $('#module-status').val(module.status);
                $('#max-events').val(module.max_events);

                // Load Step-2 settings, then populate values
                loadDataSourceSettings(function() {
                    if (module.data_source_settings) {
                        $.each(module.data_source_settings, function(key, val) {
                            $('#setting-' + key).val(val);
                        });
                        initConditionalFields();
                    }
                    step2Loaded = true;
                });

                // Load Step-3 mappings, then populate values
                loadTaxonomyMappings(function() {
                    applySavedMappings(module.taxonomy_mappings);
                    step3Loaded = true;
                });
            } else {
                alert('Error loading module: ' + response.data);
            }
        });
    }

    // Run Now (single module)
    $(document).on('click', '.chill-run-module-now', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var moduleId = $btn.data('module-id');
        $btn.prop('disabled', true).after('<span class="chill-spinner dashicons dashicons-update spin"></span>');
        $.post(ajaxurl, {
            action: 'chill_run_module_now',
            nonce: chillEventsAdmin.nonce,
            module_id: moduleId
        }, function(response) {
            $btn.prop('disabled', false);
            $btn.siblings('.chill-spinner').remove();
            if (response.success) {
                alert(response.data.message);
            } else {
                alert(response.data ? response.data : 'Import failed.');
            }
        });
    });
    // Run All Now
    $(document).on('click', '.chill-run-all-now', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).after('<span class="chill-spinner dashicons dashicons-update spin"></span>');
        $.post(ajaxurl, {
            action: 'chill_run_all_now',
            nonce: chillEventsAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $btn.siblings('.chill-spinner').remove();
            if (response.success) {
                alert(response.data.message);
            } else {
                alert(response.data ? response.data : 'Import failed.');
            }
        });
    });
}); 